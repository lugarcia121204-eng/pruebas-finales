<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InstitutionChatController extends Controller
{
    public function socketSession(Request $request)
    {
        $user = $this->user($request);

        return response()->json([
            'user_id' => $user->id, 'institution_id' => $user->institution_id,
            'session' => hash('sha256', $request->session()->getId()),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->institution_id && $user->hasAnyRole(['Admin', 'Docente'])
            && !$user->hasAnyRole(['Admin Nacional', 'Super Admin']), 403);
        return $user;
    }

    private function contacts(User $user)
    {
        return User::where('institution_id', $user->institution_id)->where('id', '!=', $user->id)
            ->whereHas('roles', fn ($roles) => $roles->where('name', $user->hasRole('Admin') ? 'Docente' : 'Admin'))
            ->whereDoesntHave('roles', fn ($roles) => $roles->whereIn('name', ['Admin Nacional', 'Super Admin']));
    }

    private function conversation(User $user, int $contact)
    {
        $this->contacts($user)->findOrFail($contact);
        return DB::table('institution_messages')->where('institution_id', $user->institution_id)
            ->where(fn ($pair) => $pair
                ->where(fn ($sent) => $sent->where('sender_id', $user->id)->where('recipient_id', $contact))
                ->orWhere(fn ($received) => $received->where('sender_id', $contact)->where('recipient_id', $user->id)));
    }

    public function index(Request $request)
    {
        $user = $this->user($request);
        $selection = $request->validate(['contact' => ['nullable', 'integer', 'min:1']]);
        if (!empty($selection['contact'])) $this->contacts($user)->findOrFail($selection['contact']);
        $contacts = $this->contacts($user)->select(['id', 'name'])
            ->selectSub(DB::table('institution_messages')->selectRaw('COUNT(*)')
                ->where('institution_id', $user->institution_id)->whereColumn('sender_id', 'users.id')
                ->where('recipient_id', $user->id)->whereNull('read_at'), 'unread_count')
            ->orderBy('name')->get();
        return Inertia::render('Chat/Index', ['contacts' => $contacts,
            'selectedContactId' => isset($selection['contact']) ? (int) $selection['contact'] : null, 'institution' => $user->institution?->name, 'isAdministrator' => $user->hasRole('Admin')]);
    }

    public function messages(Request $request, int $contact)
    {
        $user = $this->user($request);
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'before' => ['nullable', 'integer', 'min:1'], 'sync_from' => ['nullable', 'required_with:sync_through', 'integer', 'min:1'], 'sync_through' => ['nullable', 'required_with:sync_from', 'integer', 'gte:sync_from']]);
        $query = $this->conversation($user, $contact)
            ->when($data['after'] ?? null, fn ($query, $id) => $query->where('id', '>', $id))
            ->when($data['before'] ?? null, fn ($query, $id) => $query->where('id', '<', $id));
        $messages = isset($data['after'])
            ? $query->orderBy('id')->limit(50)->get()
            : $query->orderByDesc('id')->limit(50)->get()->reverse()->values();
        $serialize = fn ($message) => [
            'id' => $message->id, 'body' => $message->body, 'mine' => $message->sender_id === $user->id,
            'created_at' => \Illuminate\Support\Carbon::parse($message->created_at)->toIso8601String(), 'read_at' => $message->read_at,
        ];
        $synced = isset($data['sync_from'], $data['sync_through'])
            ? $this->conversation($user, $contact)->whereBetween('id', [$data['sync_from'], $data['sync_through']])->orderBy('id')->get()->map($serialize)
            : null;
        return response()->json(['messages' => $messages->map($serialize), 'synced' => $synced]);
    }

    public function send(Request $request, int $contact)
    {
        $user = $this->user($request);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $this->conversation($user, $contact);
        $id = DB::transaction(function () use ($user, $contact, $data) {
            $id = DB::table('institution_messages')->insertGetId([
                'institution_id' => $user->institution_id, 'sender_id' => $user->id, 'recipient_id' => $contact,
                'body' => $data['body'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($user->hasRole('Admin')) {
                Notification::create([
                    'user_id' => $contact, 'title' => 'Nuevo mensaje de tu administrador', 'type' => 'institution_chat',
                    'data' => ['message' => $user->name.': '.\Illuminate\Support\Str::limit($data['body'], 120),
                        'contact_id' => $user->id, 'message_id' => $id, 'institution_id' => $user->institution_id],
                ]);
            }
            return $id;
        });
        app(\App\Services\ChatSocketService::class)->changed($user->institution_id, $user->id, $contact);
        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, int $contact, int $message)
    {
        $user = $this->user($request);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        DB::transaction(function () use ($user, $contact, $message, $data) {
            $row = $this->conversation($user, $contact)->where('id', $message)->where('sender_id', $user->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('institution_messages')->where('id', $row->id)->update(['body' => $data['body'], 'updated_at' => now()]);
            Notification::where('type', 'institution_chat')->where('user_id', $contact)
                ->where('data->message_id', $row->id)->where('data->institution_id', $user->institution_id)
                ->get()->each(function ($notification) use ($user, $data) {
                    $payload = $notification->data;
                    $payload['message'] = $user->name.': '.\Illuminate\Support\Str::limit($data['body'], 120);
                    $notification->update(['data' => $payload]);
                });
        });
        app(\App\Services\ChatSocketService::class)->changed($user->institution_id, $user->id, $contact);
        return response()->noContent();
    }

    public function destroy(Request $request, int $contact, int $message)
    {
        $user = $this->user($request);
        DB::transaction(function () use ($user, $contact, $message) {
            $row = $this->conversation($user, $contact)->where('id', $message)->where('sender_id', $user->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            Notification::where('type', 'institution_chat')->where('user_id', $contact)
                ->where('data->message_id', $row->id)->where('data->institution_id', $user->institution_id)->delete();
            DB::table('institution_messages')->where('id', $row->id)->delete();
        });
        app(\App\Services\ChatSocketService::class)->changed($user->institution_id, $user->id, $contact);
        return response()->noContent();
    }

    public function read(Request $request, int $contact)
    {
        $user = $this->user($request);
        $data = $request->validate(['through' => ['required', 'integer', 'min:1']]);
        $this->conversation($user, $contact)->where('recipient_id', $user->id)->where('id', '<=', $data['through'])
            ->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        Notification::where('user_id', $user->id)->where('type', 'institution_chat')->whereNull('read_at')
            ->where('data->contact_id', $contact)->where('data->institution_id', $user->institution_id)
            ->where('data->message_id', '<=', $data['through'])->update(['read_at' => now()]);
        return response()->noContent();
    }
}

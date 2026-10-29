<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CatalogDocument;
use Illuminate\Support\Facades\Storage;

class PublishedFileController extends Controller
{
    public function show(string $path)
    {
        abort_if(str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/'), 404);
        $published = Announcement::whereIn('status', ['activa', 'cerrada'])->where(fn ($query) => $query->where('image_path', $path)->orWhere('file_path', $path))->exists()
            || CatalogDocument::active()->where('file_path', $path)->exists();
        abort_unless($published && Storage::disk('public')->exists($path), 404);
        return Storage::disk('public')->response($path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}

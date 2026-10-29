<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  Schema::table('users',function(Blueprint $t){ $t->string('administration_scope',20)->default('local'); });
  Schema::table('applications',function(Blueprint $t){
   $t->foreignId('institution_id')->nullable()->constrained('institutions')->restrictOnDelete();
   $t->string('review_stage',20)->default('local')->index();
   $t->string('local_status',20)->nullable()->index();
   $t->foreignId('local_decided_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('local_decided_at')->nullable(); $t->text('local_comment')->nullable();
   $t->foreignId('national_decided_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('national_decided_at')->nullable(); $t->text('national_comment')->nullable();
  });
  Schema::table('evaluations',function(Blueprint $t){ $t->string('review_stage',20)->default('local')->index(); });
  Schema::table('calendars',function(Blueprint $t){ $t->date('national_start')->nullable(); $t->date('national_end')->nullable(); });
  DB::table('applications')->orderBy('id')->each(function($a){
   DB::table('applications')->where('id',$a->id)->update([
    'institution_id'=>DB::table('users')->where('id',$a->user_id)->value('institution_id'),
    'review_stage'=>$a->status==='pending'?'local':'legacy',
    'local_status'=>$a->status==='approved'?'approved':null,
   ]);
   DB::table('evaluations')->where('application_id',$a->id)->update(['review_stage'=>$a->status==='pending'?'local':'legacy']);
  });
 }
 public function down(): void {
  Schema::table('calendars',fn(Blueprint $t)=>$t->dropColumn(['national_start','national_end']));
  Schema::table('evaluations',function(Blueprint $t){$t->dropIndex(['review_stage']);$t->dropColumn('review_stage');});
  Schema::table('applications',function(Blueprint $t){
   foreach(['institution_id','local_decided_by','national_decided_by'] as $f) $t->dropForeign([$f]);
   $t->dropIndex(['review_stage']);$t->dropIndex(['local_status']);
   $t->dropColumn(['institution_id','review_stage','local_status','local_decided_by','local_decided_at','local_comment','national_decided_by','national_decided_at','national_comment']);
  });
  Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('administration_scope'));
 }
};

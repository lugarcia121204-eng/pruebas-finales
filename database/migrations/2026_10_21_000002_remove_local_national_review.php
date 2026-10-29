<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  if(Schema::hasColumn('applications','review_stage')) {
   Schema::table('applications',function(Blueprint $t){
    $t->dropForeign(['institution_id']);$t->dropForeign(['local_decided_by']);$t->dropForeign(['national_decided_by']);
    $t->dropIndex(['review_stage']);$t->dropIndex(['local_status']);
    $t->dropColumn(['institution_id','review_stage','local_status','local_decided_by','local_decided_at','local_comment','national_decided_by','national_decided_at','national_comment']);
   });
  }
  if(Schema::hasColumn('evaluations','review_stage')) Schema::table('evaluations',function(Blueprint $t){$t->dropIndex(['review_stage']);$t->dropColumn('review_stage');});
  if(Schema::hasColumn('calendars','national_start')) Schema::table('calendars',fn(Blueprint $t)=>$t->dropColumn(['national_start','national_end']));
  if(Schema::hasColumn('users','administration_scope')) Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('administration_scope'));
  DB::table('notifications')->where('type','local_verdict')->delete();
 }
 public function down(): void { throw new RuntimeException('Restaurar los datos de etapas requiere el respaldo previo; no se reconstruyen dictámenes ficticios.'); }
};

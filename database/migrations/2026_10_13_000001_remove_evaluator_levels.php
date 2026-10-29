<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('users', function (Blueprint $t) {
   $t->dropConstrainedForeignId('evaluator_institution_id');
   $t->dropConstrainedForeignId('evaluator_state_id');
   $t->dropConstrainedForeignId('evaluator_authorized_by');
   $t->dropIndex(['evaluator_status']);
   $t->dropColumn(['evaluator_level','evaluator_status','evaluator_authorized_at']);
  });
  Schema::table('applications', function(Blueprint $t) { $t->dropIndex(['review_stage']); $t->dropColumn('review_stage'); });
  Schema::table('evaluations', function(Blueprint $t) { $t->dropIndex(['review_level']); $t->dropColumn('review_level'); });
 }
 public function down(): void {
  // Recreates the schema only. Historic level values require the private backup.
  (require __DIR__.'/2026_10_12_000001_add_evaluator_authorization_to_users.php')->up();
  (require __DIR__.'/2026_10_12_000002_add_review_stages.php')->up();
 }
};

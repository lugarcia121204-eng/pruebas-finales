<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NationalEvaluation extends Model
{
    protected $fillable = ['national_review_id', 'evaluator_id', 'status', 'score', 'answers', 'rubric_id', 'comment'];
    protected $casts = ['answers' => 'array', 'score' => 'decimal:2'];
    public function review() { return $this->belongsTo(NationalReview::class, 'national_review_id'); }
    public function evaluator() { return $this->belongsTo(User::class, 'evaluator_id'); }
    public function rubric() { return $this->belongsTo(Rubric::class); }
}

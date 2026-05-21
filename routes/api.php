<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AtRiskMemberController;
use App\Http\Controllers\Api\RetentionOfferController;
use Illuminate\Support\Facades\Route;

Route::get('/members/at-risk', [AtRiskMemberController::class, 'index']);
Route::post('/members/{memberId}/retention-offer', [RetentionOfferController::class, 'store']);

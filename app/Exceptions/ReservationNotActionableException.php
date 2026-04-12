<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ReservationNotActionableException extends Exception
{
    protected $code = 422;

    public function render($request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
        ], $this->getCode());
    }
}

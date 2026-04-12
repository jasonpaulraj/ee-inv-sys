<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class OutOfStockException extends Exception
{
    protected $message = 'Item sold out.';
    protected $code = 400;

    public function render($request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage()
        ], $this->getCode());
    }
}

<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Handler extends ExceptionHandler
{
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    protected function unauthenticated($request, \Illuminate\Auth\AuthenticationException $exception)
    {
        return new \Illuminate\Http\JsonResponse([
            'code' => 401,
            'message' => $exception->getMessage(),
        ], 401);
    }
    public function register(): void
    {
        $this->renderable(function (Throwable $exception, $request) {
            $response = [
                'code' => $exception->getCode() ?: 500,
                'exception' => get_class($exception),
            ];

            if ($exception instanceof HttpException) {
                $response['code'] = $exception->getStatusCode();
            } elseif ($exception instanceof ModelNotFoundException) {
                $response['code'] = 404;
            } elseif ($exception instanceof ValidationException) {
                $response['code'] = $exception->status;
                $response['validation'] = $exception->validator->errors();
            } elseif ($exception instanceof AuthenticationException) {
                $response['code'] = 401;
            } elseif ($exception instanceof QueryException || $exception instanceof \PDOException) {
                $response['code'] = 500;
                $response['db-code'] = $exception->getCode();
            }

            $message = $exception->getMessage();
            if (empty($message)) {
                $arr = explode('\\', get_class($exception));
                $response['message'] = trim(implode(" ", preg_split('/(?=[A-Z])/', array_pop($arr))));
            } else {
                $response['message'] = $message;
            }

            if (config('app.debug') === true) {
                $response['stack'] = explode("\n", $exception->getTraceAsString());
            }

            return new JsonResponse($response, $response['code']);
        });
    }
}

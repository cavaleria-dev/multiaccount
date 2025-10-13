<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\VendorApiService;
use App\Models\Account;

class ValidateJWT
{
    protected $vendorApi;

    public function __construct(VendorApiService $vendorApi)
    {
        $this->vendorApi = $vendorApi;
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'error' => 'JWT token required'
            ], 401);
        }

        try {
            // Валидируем JWT
            $payload = $this->vendorApi->validateJWT($token);

            if (!isset($payload->sub)) {
                throw new \Exception('Invalid token payload');
            }

            // Загружаем аккаунт
            $account = Account::find($payload->sub);

            if (!$account) {
                throw new \Exception('Account not found');
            }

            // Добавляем данные в request для использования в контроллерах
            $request->merge([
                'auth_account_id' => $account->id,
                'auth_account' => $account,
                'jwt_payload' => $payload
            ]);

            return $next($request);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid or expired token',
                'message' => $e->getMessage()
            ], 401);
        }
    }
}
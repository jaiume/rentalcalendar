<?php

namespace App\Services;

use App\DAO\UserDAO;
use App\DAO\LoginCodeDAO;
use App\DAO\AuthTokenDAO;
use DateTime;

class AuthenticationService
{
    public function __construct(
        private readonly UserDAO $userDao,
        private readonly LoginCodeDAO $loginCodeDao,
        private readonly AuthTokenDAO $authTokenDao,
        private readonly ConfigService $config
    ) {
    }

    public function userExists(string $email): bool
    {
        try {
            return $this->userDao->userExists($email);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getUserByEmail(string $email): ?int
    {
        try {
            return $this->userDao->getUserIdByEmail($email);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function generateLoginCode(string $email): ?array
    {
        try {
            $userId = $this->getUserByEmail($email);
            if (!$userId) {
                return null;
            }

            // Generate 6-digit code
            $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

            // Generate token for direct link
            $tokenLength = (int) $this->config::get('auth.token_length', 32);
            $token = bin2hex(random_bytes(max(16, $tokenLength / 2)));

            // Code expires in 15 minutes, token expires in 1 week
            $codeExpiry = (new DateTime())->modify('+15 minutes');
            $tokenExpirySeconds = (int) $this->config::get('auth.token_expiry', 604800);
            $tokenExpiry = (new DateTime())->modify("+{$tokenExpirySeconds} seconds");

            // Store code - use REPLACE or DELETE then INSERT to handle duplicates
            // First delete any existing code for this user
            $this->loginCodeDao->deleteByUserId($userId);

            // Then insert the new code
            $this->loginCodeDao->create(
                $userId,
                password_hash($code, PASSWORD_DEFAULT),
                password_hash($token, PASSWORD_DEFAULT),
                $codeExpiry,
                $tokenExpiry
            );

            return [
                'code' => $code,
                'token' => $token,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function verifyLoginCode(string $code): ?string
    {
        try {
            LogService::debug('Verifying login code');
            $this->loginCodeDao->cleanupExpired();

            $rows = $this->loginCodeDao->findNonExpiredCodes();
            LogService::debug('Found non-expired codes', ['count' => count($rows)]);

            foreach ($rows as $row) {
                if (password_verify($code, $row['code'])) {
                    // Code is valid, generate a new session token
                    $userId = $row['user_id'];
                    LogService::info('Login code verified successfully', ['user_id' => $userId]);
                    
                    $tokenLength = (int) $this->config::get('auth.token_length', 32);
                    $sessionToken = bin2hex(random_bytes(max(16, $tokenLength / 2)));
                    
                    LogService::debug('Creating auth token for user', [
                        'user_id' => $userId,
                        'token_length' => strlen($sessionToken),
                        'token_preview' => substr($sessionToken, 0, 10) . '...'
                    ]);
                    
                    $hashedToken = password_hash($sessionToken, PASSWORD_DEFAULT);
                    $tokenId = $this->authTokenDao->create($userId, $hashedToken);
                    
                    LogService::debug('Auth token created in database', [
                        'token_id' => $tokenId,
                        'hashed_length' => strlen($hashedToken)
                    ]);

                    // Delete used login code
                    $this->loginCodeDao->deleteById($row['login_code_id']);
                    LogService::debug('Deleted used login code');

                    return $sessionToken;
                }
            }

            LogService::warning('Login code verification failed: no matching code found');
            return null;
        } catch (\Throwable $e) {
            LogService::exception($e, 'Error verifying login code');
            return null;
        }
    }

    public function verifyLoginToken(string $token): ?string
    {
        try {
            $this->loginCodeDao->cleanupExpired();

            $rows = $this->loginCodeDao->findNonExpiredTokens();

            foreach ($rows as $row) {
                if (password_verify($token, $row['token'])) {
                    // Token is valid, create auth session
                    $userId = $row['user_id'];
                    
                    // Generate new session token
                    $tokenLength = (int) $this->config::get('auth.token_length', 32);
                    $sessionToken = bin2hex(random_bytes(max(16, $tokenLength / 2)));
                    
                    $this->authTokenDao->create($userId, password_hash($sessionToken, PASSWORD_DEFAULT));

                    // Delete used login code
                    $this->loginCodeDao->deleteById($row['login_code_id']);

                    return $sessionToken;
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function cleanupExpiredCodes(): void
    {
        $this->loginCodeDao->cleanupExpired();
    }

    public function generateToken(string $email): ?string
    {
        try {
            $userId = $this->getUserByEmail($email);
            if (!$userId) {
                throw new \RuntimeException('User not found');
            }

            $tokenLength = (int) $this->config::get('auth.token_length', 32);
            $token = bin2hex(random_bytes(max(16, $tokenLength / 2)));

            $this->authTokenDao->create($userId, password_hash($token, PASSWORD_DEFAULT));

            return $token;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function verifyToken(string $token): ?array
    {
        try {
            $this->authTokenDao->cleanupExpired(
                (int) $this->config::get('auth.auth_token_expiry', 604800)
            );

            $expirySeconds = (int) $this->config::get('auth.auth_token_expiry', 604800);
            $rows = $this->authTokenDao->findAllNonExpiredWithUserDetails($expirySeconds);

            LogService::debug('Verifying auth token', [
                'non_expired_tokens_count' => count($rows),
                'token_preview' => substr($token, 0, 10) . '...',
                'token_length' => strlen($token)
            ]);

            foreach ($rows as $row) {
                $matches = password_verify($token, $row['token']);
                LogService::debug('Checking token against database', [
                    'token_id' => $row['auth_token_id'],
                    'user_id' => $row['user_id'],
                    'matches' => $matches
                ]);
                
                if ($matches) {
                    // Token is valid - update last_touched to extend expiry
                    $this->authTokenDao->updateLastTouched($row['auth_token_id']);

                    LogService::info('Auth token verified successfully', [
                        'user_id' => $row['user_id'],
                        'email' => $row['email']
                    ]);

                    return [
                        'user_id' => $row['user_id'],
                        'email' => $row['email'],
                        'name' => $row['name'],
                        'is_admin' => (bool) $row['is_admin'],
                    ];
                }
            }

            LogService::warning('Auth token verification failed: no matching token found');
            return null;
        } catch (\Throwable $e) {
            LogService::exception($e, 'Error verifying auth token');
            return null;
        }
    }

    public function extendTokenExpiry(string $token): bool
    {
        try {
            $expirySeconds = (int) $this->config::get('auth.auth_token_expiry', 604800);

            // Find the token by verifying it against all non-expired tokens
            $rows = $this->authTokenDao->findAllNonExpired($expirySeconds);

            foreach ($rows as $row) {
                if (password_verify($token, $row['token'])) {
                    // Found the token, update its last_touched to extend expiry
                    return $this->authTokenDao->updateLastTouched($row['auth_token_id']);
                }
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function cleanupExpiredTokens(): void
    {
        $expirySeconds = (int) $this->config::get('auth.auth_token_expiry', 604800);
        $this->authTokenDao->cleanupExpired($expirySeconds);
    }

    public function deleteToken(string $token): void
    {
        $expirySeconds = (int) $this->config::get('auth.auth_token_expiry', 604800);
        
        // Find the token by verifying it against all non-expired tokens
        $rows = $this->authTokenDao->findAllNonExpired($expirySeconds);

        foreach ($rows as $row) {
            if (password_verify($token, $row['token'])) {
                // Found the token, delete it
                $this->authTokenDao->deleteById($row['auth_token_id']);
                return;
            }
        }
    }
}


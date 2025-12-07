# Debug Mode & Logging

This application includes comprehensive debugging and logging capabilities to help diagnose issues, especially in production environments.

## Enabling Debug Mode

Debug mode is controlled by the `debug` flag in `config/config.ini`:

```ini
[app]
debug = true  ; Set to false in production
```

## Features

### 1. Application Logging

The `LogService` provides structured logging with different severity levels:

- **DEBUG**: Detailed diagnostic information (only logged when debug mode is enabled)
- **INFO**: General informational messages
- **WARNING**: Warning messages for potential issues
- **ERROR**: Error messages for failures

#### Usage in Code

```php
use App\Services\LogService;

// Debug messages (only when debug mode is enabled)
LogService::debug('Processing user login', ['user_id' => $userId]);

// Info messages
LogService::info('User logged in successfully', ['email' => $email]);

// Warnings
LogService::warning('Failed login attempt', ['email' => $email]);

// Errors
LogService::error('Database connection failed', ['host' => $host]);

// Exceptions with full stack trace
try {
    // ... code ...
} catch (\Throwable $e) {
    LogService::exception($e, 'Context information');
}
```

### 2. Debug Web Interface

When debug mode is enabled, you can access:

#### View Logs
**URL**: `/debug/logs`

Features:
- View recent log entries (50-1000 lines)
- Color-coded by severity (ERROR=red, WARNING=yellow, INFO=blue, DEBUG=gray)
- Auto-refresh every 10 seconds (optional)
- Scroll to bottom automatically
- Clear all logs

#### Server Information
**URL**: `/debug/info`

Shows:
- PHP version and configuration
- Current cookies set in browser
- Server variables (HTTPS, ports, etc.)
- Helpful for diagnosing cookie/session issues

### 3. Authentication Flow Logging

The authentication system includes detailed logging:

**AuthController**:
- Code verification attempts
- Direct login attempts  
- Cookie setting operations

**AuthenticationMiddleware**:
- Every authentication check
- Cookie presence/absence
- Token verification results
- User session details

**AuthenticationService**:
- Login code verification
- Auth token verification
- Database query results

## Diagnosing Login Issues

The most common login issue is cookies not being set. Here's how to debug:

### Step 1: Enable Debug Mode on Production

Edit `config/config.ini` on production:
```ini
[app]
debug = true
```

### Step 2: Attempt Login

1. Go to the login page
2. Enter your email
3. Enter the 6-digit code
4. Note if you're redirected back to login

### Step 3: Check Logs

Visit `/debug/logs` and look for:

```
[DEBUG] Code verification attempt
[INFO] Code verified successfully, setting cookie and redirecting
[DEBUG] Setting auth cookie
[DEBUG] Cookie header prepared
```

Then on the redirect to `/dashboard`:

```
[DEBUG] AuthenticationMiddleware invoked
[WARNING] Authentication failed: no token in cookies
```

This tells us the cookie wasn't received by the browser.

### Step 4: Check Server Info

Visit `/debug/info` to check:
- Is `HTTPS` set correctly? (should be "on" for HTTPS sites)
- Is `SERVER_PORT` 443 (HTTPS) or 80 (HTTP)?
- Are any cookies showing in the browser?

### Common Issues

**Issue**: Cookie not set because site is HTTPS but `$_SERVER['HTTPS']` is not "on"

**Solution**: Check your web server configuration. Some reverse proxies don't set the HTTPS header correctly.

**Issue**: Cookie set with `Secure` flag on HTTP site

**Solution**: The cookie will be rejected by browsers. Either enable HTTPS or modify the code to not set Secure flag.

**Issue**: Cookie path mismatch

**Solution**: Cookies are set for path `/`. If your app is in a subdirectory, you may need to adjust.

## Log File Location

Logs are stored in: `/logs/app.log`

You can also access logs via SSH:
```bash
tail -f /path/to/logs/app.log
```

## Security Notes

1. **Always disable debug mode in production after troubleshooting**
2. Debug pages are accessible without authentication (but only when debug=true)
3. Log files may contain sensitive information - don't commit them to git
4. The `/logs/` directory is excluded from git via `.gitignore`

## Disabling Debug Mode

After resolving issues, set in `config/config.ini`:
```ini
[app]
debug = false
```

This will:
- Disable DEBUG level logging
- Block access to `/debug/*` endpoints
- Reduce log file growth


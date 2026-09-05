<?php
/**
 * Multi-User Management
 * CRUD operations for content/users.json
 * Handles migration from single-user config.php constants.
 */

require_once __DIR__ . '/../includes/json-store.php';

if (!defined('USERS_PATH')) {
    define('USERS_PATH', __DIR__ . '/../content/users.json');
}

/**
 * Load all users from users.json.
 * Returns ['users' => [...]] or empty structure.
 */
function loadUsers(): array {
    if (!file_exists(USERS_PATH)) {
        return ['users' => []];
    }
    $data = json_decode(file_get_contents(USERS_PATH), true);
    if (!is_array($data) || !is_array($data['users'] ?? null)) {
        return ['users' => []];
    }
    return $data;
}

/**
 * Save users array to users.json.
 */
function saveUsers(array $data): bool {
    return nibblyJsonUpdate(USERS_PATH, function (array &$stored) use ($data): void { $stored = $data; }, ['users' => []]);
}

/** Mutate one account while holding the shared user-store lock. */
function updateUserRecord(string $userId, callable $update): bool {
    return nibblyJsonUpdate(USERS_PATH, function (array &$data) use ($userId, $update): bool {
        if (!is_array($data['users'] ?? null)) return false;
        foreach ($data['users'] as &$user) {
            if (($user['id'] ?? '') === $userId) return $update($user, $data) !== false;
        }
        return false;
    }, ['users' => []]);
}

/**
 * Find user by username (case-insensitive).
 */
function findUserByUsername(string $username): ?array {
    $data = loadUsers();
    $lower = strtolower($username);
    foreach ($data['users'] as $user) {
        if (strtolower($user['username']) === $lower) {
            return $user;
        }
    }
    return null;
}

/**
 * Find user by email (case-insensitive).
 */
function findUserByEmail(string $email): ?array {
    $data = loadUsers();
    $lower = strtolower($email);
    foreach ($data['users'] as $user) {
        if (!empty($user['email']) && strtolower($user['email']) === $lower) {
            return $user;
        }
    }
    return null;
}

/**
 * Find user by ID.
 */
function findUserById(string $id): ?array {
    $data = loadUsers();
    foreach ($data['users'] as $user) {
        if ($user['id'] === $id) {
            return $user;
        }
    }
    return null;
}

/**
 * Verify username + password. Returns user array on success, false on failure.
 */
function verifyUserPassword(string $username, string $password) {
    $user = findUserByUsername($username);
    if (!$user) {
        return false;
    }
    if (!password_verify($password, $user['passwordHash'])) {
        return false;
    }
    return $user;
}

/**
 * Create a new user. Returns the user array.
 */
function createUser(string $username, string $email, string $password, string $role, string $createdBy): array {
    $user = [
        'id' => 'u_' . bin2hex(random_bytes(5)),
        'username' => $username,
        'email' => $email,
        'role' => in_array($role, ['admin', 'editor']) ? $role : 'editor',
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
        'createdAt' => gmdate('c'),
        'createdBy' => $createdBy,
        'lastLogin' => null,
        'resetToken' => null,
        'resetTokenExpiry' => null,
    ];

    $saved = nibblyJsonUpdate(USERS_PATH, function (array &$data) use ($user): bool {
        if (!is_array($data['users'] ?? null)) return false;
        foreach ($data['users'] as $existing) {
            if (strcasecmp($existing['username'], $user['username']) === 0
                || ($user['email'] !== '' && strcasecmp($existing['email'] ?? '', $user['email']) === 0)) return false;
        }
        $data['users'][] = $user;
        return true;
    }, ['users' => []]);
    if (!$saved) throw new RuntimeException('Could not create user; account may already exist or storage is unavailable.');

    return $user;
}

/**
 * Update user fields (username, email, role). Does NOT update password.
 */
function updateUser(string $userId, array $fields): bool {
    return updateUserRecord($userId, function (array &$user, array $data) use ($fields): bool {
        if (isset($fields['role']) && !in_array($fields['role'], ['admin', 'editor'], true)) return false;
        if ($user['role'] === 'admin' && ($fields['role'] ?? 'admin') !== 'admin'
            && count(array_filter($data['users'], fn($u) => $u['role'] === 'admin')) <= 1) return false;
        foreach ($data['users'] as $other) {
            if ($other['id'] === $user['id']) continue;
            foreach (['username', 'email'] as $key) {
                if (!empty($fields[$key]) && strcasecmp($other[$key] ?? '', $fields[$key]) === 0) return false;
            }
        }
        foreach (['username', 'email', 'role'] as $key) {
            if (array_key_exists($key, $fields)) $user[$key] = $fields[$key];
        }
        return true;
    });
}

/**
 * Update a user's password hash.
 */
function updateUserPassword(string $userId, string $newHash): bool {
    return updateUserRecord($userId, function (array &$user) use ($newHash): void {
        $user['passwordHash'] = $newHash;
        $user['resetToken'] = null;
        $user['resetTokenExpiry'] = null;
    });
}

/**
 * Update last login timestamp.
 */
function updateUserLastLogin(string $userId): bool {
    return updateUserRecord($userId, function (array &$user): void { $user['lastLogin'] = gmdate('c'); });
}

/**
 * Delete a user by ID.
 */
function deleteUser(string $userId): bool {
    return nibblyJsonUpdate(USERS_PATH, function (array &$data) use ($userId): bool {
        if (!is_array($data['users'] ?? null)) return false;
        $filtered = [];
        $found = false;
        foreach ($data['users'] as $user) {
            if ($user['id'] === $userId) {
                if ($user['role'] === 'admin' && count(array_filter($data['users'], fn($u) => $u['role'] === 'admin')) <= 1) return false;
                $found = true;
            } else {
                $filtered[] = $user;
            }
        }
        if (!$found) {
            return false;
        }
        $data['users'] = $filtered;
        return true;
    }, ['users' => []]);
}

/**
 * Count users with a given role.
 */
function countUsersByRole(string $role): int {
    $data = loadUsers();
    $count = 0;
    foreach ($data['users'] as $user) {
        if ($user['role'] === $role) {
            $count++;
        }
    }
    return $count;
}

/**
 * Generate a password reset token. Stores SHA-256 hash in users.json,
 * returns the raw token (to be sent via email).
 */
function generateResetToken(string $userId): ?string {
    $rawToken = bin2hex(random_bytes(32));
    $hashedToken = hash('sha256', $rawToken);

    $saved = updateUserRecord($userId, function (array &$user) use ($hashedToken): void {
        $user['resetToken'] = $hashedToken;
        $user['resetTokenExpiry'] = time() + 3600;
    });
    return $saved ? $rawToken : null;
}

/**
 * Validate a reset token. Returns user array if valid, false otherwise.
 */
function validateResetToken(string $rawToken) {
    $hashedToken = hash('sha256', $rawToken);
    $data = loadUsers();

    foreach ($data['users'] as $user) {
        if ($user['resetToken'] === $hashedToken) {
            if (($user['resetTokenExpiry'] ?? 0) > time()) {
                return $user;
            }
            // A newer token may have been issued since this snapshot was read.
            // Expired tokens are rejected without overwriting the account.
            return false;
        }
    }
    return false;
}

/**
 * Clear reset token for a user.
 */
function clearResetToken(string $userId): bool {
    return updateUserRecord($userId, function (array &$user): void {
        $user['resetToken'] = null;
        $user['resetTokenExpiry'] = null;
    });
}

/** Validate and consume a reset token in the same transaction as the password. */
function completePasswordReset(string $userId, string $rawToken, string $newHash): bool {
    return updateUserRecord($userId, function (array &$user) use ($rawToken, $newHash): bool {
        if (($user['resetTokenExpiry'] ?? 0) <= time()
            || !hash_equals((string)($user['resetToken'] ?? ''), hash('sha256', $rawToken))) return false;
        $user['passwordHash'] = $newHash;
        $user['resetToken'] = null;
        $user['resetTokenExpiry'] = null;
        return true;
    });
}

/**
 * Migrate from single-user config.php constants to users.json.
 * Called automatically when users.json does not exist but ADMIN_USERNAME is defined.
 */
function migrateFromConfig(): void {
    if (file_exists(USERS_PATH)) {
        return;
    }
    if (!defined('ADMIN_USERNAME') || !defined('ADMIN_PASSWORD_HASH')) {
        return;
    }

    $data = [
        'users' => [
            [
                'id' => 'u_' . bin2hex(random_bytes(5)),
                'username' => ADMIN_USERNAME,
                'email' => '',
                'role' => 'admin',
                'passwordHash' => ADMIN_PASSWORD_HASH,
                'createdAt' => gmdate('c'),
                'createdBy' => 'migration',
                'lastLogin' => null,
                'resetToken' => null,
                'resetTokenExpiry' => null,
            ]
        ]
    ];

    saveUsers($data);
}

/**
 * Ensure users.json exists. Migrates from config.php if needed.
 * Call this early in any file that needs user data.
 */
function ensureUsersFile(): void {
    if (!file_exists(USERS_PATH)) {
        migrateFromConfig();
    }
}

/**
 * Get user list safe for API output (strips sensitive fields).
 */
function getUsersForApi(): array {
    $data = loadUsers();
    $result = [];
    foreach ($data['users'] as $user) {
        $result[] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'] ?? '',
            'role' => $user['role'],
            'createdAt' => $user['createdAt'],
            'lastLogin' => $user['lastLogin'],
        ];
    }
    return $result;
}

/**
 * Check if the current session user has admin role.
 */
function isAdmin(): bool {
    return ($_SESSION['admin_role'] ?? '') === 'admin';
}

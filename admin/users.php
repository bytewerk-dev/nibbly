<?php
/**
 * Multi-User Management
 * CRUD operations for content/users.json
 * Handles migration from single-user config.php constants.
 */

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
    if (!is_array($data) || !isset($data['users'])) {
        return ['users' => []];
    }
    return $data;
}

/**
 * Save users array to users.json.
 */
function saveUsers(array $data): bool {
    $dir = dirname(USERS_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents(USERS_PATH, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
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
    $data = loadUsers();

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

    $data['users'][] = $user;
    saveUsers($data);

    return $user;
}

/**
 * Update user fields (username, email, role). Does NOT update password.
 */
function updateUser(string $userId, array $fields): bool {
    $data = loadUsers();
    $allowedFields = ['username', 'email', 'role'];

    foreach ($data['users'] as &$user) {
        if ($user['id'] === $userId) {
            foreach ($fields as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    $user[$key] = $value;
                }
            }
            return saveUsers($data);
        }
    }
    return false;
}

/**
 * Update a user's password hash.
 */
function updateUserPassword(string $userId, string $newHash): bool {
    $data = loadUsers();
    foreach ($data['users'] as &$user) {
        if ($user['id'] === $userId) {
            $user['passwordHash'] = $newHash;
            return saveUsers($data);
        }
    }
    return false;
}

/**
 * Update last login timestamp.
 */
function updateUserLastLogin(string $userId): bool {
    $data = loadUsers();
    foreach ($data['users'] as &$user) {
        if ($user['id'] === $userId) {
            $user['lastLogin'] = gmdate('c');
            return saveUsers($data);
        }
    }
    return false;
}

/**
 * Delete a user by ID.
 */
function deleteUser(string $userId): bool {
    $data = loadUsers();
    $filtered = [];
    $found = false;
    foreach ($data['users'] as $user) {
        if ($user['id'] === $userId) {
            $found = true;
        } else {
            $filtered[] = $user;
        }
    }
    if (!$found) {
        return false;
    }
    $data['users'] = $filtered;
    return saveUsers($data);
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
    $data = loadUsers();
    $rawToken = bin2hex(random_bytes(32));
    $hashedToken = hash('sha256', $rawToken);

    foreach ($data['users'] as &$user) {
        if ($user['id'] === $userId) {
            $user['resetToken'] = $hashedToken;
            $user['resetTokenExpiry'] = time() + 3600; // 1 hour
            saveUsers($data);
            return $rawToken;
        }
    }
    return null;
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
            // Token expired — clear it
            clearResetToken($user['id']);
            return false;
        }
    }
    return false;
}

/**
 * Clear reset token for a user.
 */
function clearResetToken(string $userId): bool {
    $data = loadUsers();
    foreach ($data['users'] as &$user) {
        if ($user['id'] === $userId) {
            $user['resetToken'] = null;
            $user['resetTokenExpiry'] = null;
            return saveUsers($data);
        }
    }
    return false;
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

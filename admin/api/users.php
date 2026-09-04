<?php
if (!defined('NIBBLY_ADMIN_DIR')) { http_response_code(404); exit; }

// Authenticated dispatcher supplies shared helpers and request context.
switch ($action) {
    case 'change-password':
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $newPasswordConfirm = $_POST['new_password_confirm'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($newPasswordConfirm)) {
            jsonResponse(false, null, 'All fields are required');
        }

        $userId = $_SESSION['admin_user_id'] ?? '';
        $currentUser = findUserById($userId);
        if (!$currentUser) {
            jsonResponse(false, null, 'User not found');
        }

        if (!password_verify($currentPassword, $currentUser['passwordHash'])) {
            jsonResponse(false, null, 'Current password is incorrect');
        }

        if ($newPassword !== $newPasswordConfirm) {
            jsonResponse(false, null, 'New passwords do not match');
        }

        if ($currentPassword === $newPassword) {
            jsonResponse(false, null, 'New password must be different from current password');
        }

        // Password strength check
        if (strlen($newPassword) < 8 ||
            !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword) ||
            !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            jsonResponse(false, null, 'Password does not meet requirements: at least 8 characters with uppercase, lowercase, digits, and special characters');
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!updateUserPassword($userId, $newHash)) {
            jsonResponse(false, null, 'Could not update password');
        }

        // Keep this session valid; other sessions must authenticate again.
        $_SESSION['admin_password_fingerprint'] = hash('sha256', $newHash);
        session_regenerate_id(true);

        // Clear password warning
        unset($_SESSION['password_warning']);

        jsonResponse(true, null, 'Password changed successfully');
        break;

    // ============================================================
    // SETTINGS
    // ============================================================

    case 'list-users':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        jsonResponse(true, getUsersForApi());
        break;

    case 'create-user':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $newUsername = trim($_POST['username'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');
        $newRole = $_POST['role'] ?? 'editor';
        $newPw = $_POST['password'] ?? '';

        if (empty($newUsername) || strlen($newUsername) < 3) {
            jsonResponse(false, null, 'Username must be at least 3 characters');
        }
        if (findUserByUsername($newUsername)) {
            jsonResponse(false, null, 'Username already exists');
        }
        if (!empty($newEmail) && findUserByEmail($newEmail)) {
            jsonResponse(false, null, 'Email already in use');
        }
        if (empty($newPw)) {
            jsonResponse(false, null, 'Password is required');
        }
        if (strlen($newPw) < 8 ||
            !preg_match('/[A-Z]/', $newPw) ||
            !preg_match('/[a-z]/', $newPw) ||
            !preg_match('/[0-9]/', $newPw) ||
            !preg_match('/[^A-Za-z0-9]/', $newPw)) {
            jsonResponse(false, null, 'Password does not meet requirements');
        }

        $createdBy = $_SESSION['admin_username'] ?? 'admin';
        try {
            $newUser = createUser($newUsername, $newEmail, $newPw, $newRole, $createdBy);
        } catch (RuntimeException $error) {
            jsonResponse(false, null, $error->getMessage());
        }
        jsonResponse(true, [
            'id' => $newUser['id'],
            'username' => $newUser['username'],
            'email' => $newUser['email'],
            'role' => $newUser['role'],
        ], 'User created');
        break;

    case 'update-user':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $editUserId = $_POST['user_id'] ?? '';
        $editUser = findUserById($editUserId);
        if (!$editUser) {
            jsonResponse(false, null, 'User not found');
        }

        $fields = [];
        if (isset($_POST['username'])) {
            $uname = trim($_POST['username']);
            if (strlen($uname) < 3) {
                jsonResponse(false, null, 'Username must be at least 3 characters');
            }
            $existing = findUserByUsername($uname);
            if ($existing && $existing['id'] !== $editUserId) {
                jsonResponse(false, null, 'Username already exists');
            }
            $fields['username'] = $uname;
        }
        if (isset($_POST['email'])) {
            $uemail = trim($_POST['email']);
            if (!empty($uemail)) {
                $existing = findUserByEmail($uemail);
                if ($existing && $existing['id'] !== $editUserId) {
                    jsonResponse(false, null, 'Email already in use');
                }
            }
            $fields['email'] = $uemail;
        }
        if (isset($_POST['role'])) {
            $newRole = $_POST['role'];
            if (!in_array($newRole, ['admin', 'editor'])) {
                jsonResponse(false, null, 'Invalid role');
            }
            // Prevent demoting the last admin
            if ($editUser['role'] === 'admin' && $newRole === 'editor' && countUsersByRole('admin') <= 1) {
                jsonResponse(false, null, 'Cannot demote the last admin');
            }
            $fields['role'] = $newRole;
        }

        if (!empty($fields)) {
            if (!updateUser($editUserId, $fields)) jsonResponse(false, null, 'Could not update user');

            // Update session if editing self
            if ($editUserId === ($_SESSION['admin_user_id'] ?? '')) {
                if (isset($fields['username'])) $_SESSION['admin_username'] = $fields['username'];
                if (isset($fields['role'])) $_SESSION['admin_role'] = $fields['role'];
            }
        }

        jsonResponse(true, null, 'User updated');
        break;

    case 'delete-user':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $delUserId = $_POST['user_id'] ?? '';
        if ($delUserId === ($_SESSION['admin_user_id'] ?? '')) {
            jsonResponse(false, null, 'Cannot delete yourself');
        }

        $delUser = findUserById($delUserId);
        if (!$delUser) {
            jsonResponse(false, null, 'User not found');
        }

        if ($delUser['role'] === 'admin' && countUsersByRole('admin') <= 1) {
            jsonResponse(false, null, 'Cannot delete the last admin');
        }

        if (!deleteUser($delUserId)) jsonResponse(false, null, 'Could not delete user');
        jsonResponse(true, null, 'User deleted');
        break;

    case 'admin-reset-password':
        if (!isAdmin()) {
            jsonResponse(false, null, 'Forbidden');
        }
        if (!validateCsrfToken()) {
            jsonResponse(false, null, 'Invalid CSRF token');
        }

        $resetUserId = $_POST['user_id'] ?? '';
        $resetNewPw = $_POST['password'] ?? '';

        $resetUser = findUserById($resetUserId);
        if (!$resetUser) {
            jsonResponse(false, null, 'User not found');
        }

        if (empty($resetNewPw)) {
            jsonResponse(false, null, 'Password is required');
        }
        if (strlen($resetNewPw) < 8 ||
            !preg_match('/[A-Z]/', $resetNewPw) ||
            !preg_match('/[a-z]/', $resetNewPw) ||
            !preg_match('/[0-9]/', $resetNewPw) ||
            !preg_match('/[^A-Za-z0-9]/', $resetNewPw)) {
            jsonResponse(false, null, 'Password does not meet requirements');
        }

        $resetHash = password_hash($resetNewPw, PASSWORD_DEFAULT);
        if (!updateUserPassword($resetUserId, $resetHash)) jsonResponse(false, null, 'Could not save password');
        jsonResponse(true, null, 'Password reset successfully');
        break;

    // ============================================================
    // MENU ORDER
    // ============================================================

}

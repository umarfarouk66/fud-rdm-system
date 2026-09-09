<?php
/**
 * Registration Processing
 * RDM Information System
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../public/register.php');
    exit;
}

// Extract and sanitize input data
$firstName       = sanitize_input($_POST['first_name'] ?? '');
$lastName        = sanitize_input($_POST['last_name'] ?? '');
$email           = trim($_POST['email'] ?? '');
$phone           = sanitize_input($_POST['phone'] ?? '');
$institution     = sanitize_input($_POST['institution'] ?? '');
$faculty         = sanitize_input($_POST['faculty'] ?? '');
$department      = sanitize_input($_POST['department'] ?? '');
$roleName        = strtolower(trim($_POST['role'] ?? ''));
$password        = $_POST['password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

// Save old input for form re-population (excluding passwords)
$_SESSION['old_register'] = [
    'first_name'  => $firstName,
    'last_name'   => $lastName,
    'email'       => $email,
    'phone'       => $phone,
    'institution' => $institution,
    'faculty'     => $faculty,
    'department'  => $department,
    'role'        => $roleName
];

$errors = [];

// Validation: Required fields
if (empty($firstName)) {
    $errors[] = 'First name is required.';
} elseif (strlen($firstName) > 100) {
    $errors[] = 'First name cannot exceed 100 characters.';
}

if (empty($lastName)) {
    $errors[] = 'Last name is required.';
} elseif (strlen($lastName) > 100) {
    $errors[] = 'Last name cannot exceed 100 characters.';
}

if (empty($email)) {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
} elseif (strlen($email) > 191) {
    $errors[] = 'Email cannot exceed 191 characters.';
}

// Validation: Role restriction (Public registration allows: researcher, librarian; never supervisor or admin)
$allowedRoles = ['researcher', 'librarian'];
if (empty($roleName)) {
    $errors[] = 'Please select a valid role.';
} elseif (!in_array($roleName, $allowedRoles, true)) {
    $errors[] = 'Invalid role selected. Supervisor and Administrator accounts must be assigned by the system administrator.';
}

// Validation: Password strength and confirmation
if (empty($password)) {
    $errors[] = 'Password is required.';
} elseif (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}

if ($password !== $confirmPassword) {
    $errors[] = 'Password confirmation does not match.';
}

// Database validation if basic checks pass
if (empty($errors)) {
    try {
        // 1. Check if email already exists
        $emailStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $emailStmt->execute([':email' => $email]);
        if ($emailStmt->fetch()) {
            $errors[] = 'An account with this email address already exists. Please log in or use a different email.';
        } else {
            // 2. Fetch role ID from roles table
            $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = :role_name LIMIT 1");
            $roleStmt->execute([':role_name' => $roleName]);
            $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$roleRow) {
                $errors[] = 'Selected role could not be verified in the database.';
            } else {
                $roleId = (int)$roleRow['id'];

                // 3. Hash the password securely
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // 4. Insert the new user
                $insertSql = "
                    INSERT INTO users (
                        role_id,
                        first_name,
                        last_name,
                        email,
                        password_hash,
                        phone,
                        institution,
                        faculty,
                        department,
                        status
                    ) VALUES (
                        :role_id,
                        :first_name,
                        :last_name,
                        :email,
                        :password_hash,
                        :phone,
                        :institution,
                        :faculty,
                        :department,
                        'active'
                    )
                ";

                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([
                    ':role_id'       => $roleId,
                    ':first_name'    => $firstName,
                    ':last_name'     => $lastName,
                    ':email'         => $email,
                    ':password_hash' => $passwordHash,
                    ':phone'         => !empty($phone) ? $phone : null,
                    ':institution'   => !empty($institution) ? $institution : null,
                    ':faculty'       => !empty($faculty) ? $faculty : null,
                    ':department'    => !empty($department) ? $department : null
                ]);

                // Clear temporary session data
                unset($_SESSION['old_register']);
                unset($_SESSION['register_errors']);

                // Set success message and redirect to login
                $_SESSION['auth_success'] = 'Registration successful! You can now log in with your credentials.';
                header('Location: ../public/login.php?registered=1');
                exit;
            }
        }
    } catch (PDOException $e) {
        error_log("Registration DB Error: " . $e->getMessage());
        $errors[] = 'A system error occurred during registration. Please try again later.';
    }
}

// If there are errors, redirect back to registration
$_SESSION['register_errors'] = $errors;
header('Location: ../public/register.php');
exit;

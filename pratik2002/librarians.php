<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: index.php");
    exit;
}

$alertMessage = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_librarian'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $username === '' || strlen($password) < 6) {
        $alertMessage = 'Please enter name, email, username, and a password of at least 6 characters.';
        $alertType = 'alert-error';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO admin (name, email, username, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $username, password_hash($password, PASSWORD_DEFAULT)]);
            $alertMessage = 'Librarian/admin account added successfully.';
            $alertType = 'alert-success';
        } catch (PDOException $e) {
            $alertMessage = 'Could not add librarian. Email or username may already exist.';
            $alertType = 'alert-error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_librarian'], $_POST['admin_id'])) {
    $adminId = (int) $_POST['admin_id'];
    if ($adminId === (int) $_SESSION['admin_id']) {
        $alertMessage = 'You cannot delete your own logged-in account.';
        $alertType = 'alert-error';
    } else {
        $stmt = $pdo->prepare("DELETE FROM admin WHERE admin_id = ?");
        $stmt->execute([$adminId]);
        $alertMessage = 'Librarian/admin account deleted.';
        $alertType = 'alert-success';
    }
}

$librarians = $pdo->query("SELECT admin_id, name, email, username, created_at FROM admin ORDER BY created_at DESC")->fetchAll();
$pageTitle = 'Librarian Management';
$showBackButton = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librarians - library Management</title>
    <link rel="stylesheet" href="Dashboard.css">
    <style>.form-card{background:#fff;border-radius:12px;padding:22px;margin-bottom:20px;box-shadow:0 4px 12px rgba(15,23,42,.06)}.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.form-grid input{padding:10px;border:1px solid var(--border-gray);border-radius:8px}</style>
</head>
<body>
<?php include 'header.php'; ?>
<div class="table-container">
    <?php if ($alertMessage): ?><div class="alert <?= $alertType ?>"><?= htmlspecialchars($alertMessage) ?></div><?php endif; ?>
    <div class="form-card">
        <h2>Add Librarian</h2>
        <form method="POST" class="form-grid">
            <input type="text" name="name" placeholder="Full name" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" minlength="6" required>
            <button class="btn-primary" name="add_librarian" type="submit">Add Librarian</button>
        </form>
    </div>
    <div class="table-header"><h2>Manage Librarians</h2></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Email</th><th>Username</th><th>Created</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($librarians as $l): ?>
        <tr><td><?= htmlspecialchars($l['name'] ?? '') ?></td><td><?= htmlspecialchars($l['email']) ?></td><td><?= htmlspecialchars($l['username']) ?></td><td><?= htmlspecialchars($l['created_at']) ?></td><td>
            <?php if ((int)$l['admin_id'] !== (int)$_SESSION['admin_id']): ?><form method="POST" onsubmit="return confirm('Delete this librarian account?')"><input type="hidden" name="admin_id" value="<?= $l['admin_id'] ?>"><button class="btn-action" style="background:#dc2626" name="delete_librarian">Delete</button></form><?php else: ?><span class="text-sm">Current account</span><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
</div>
<?php include 'footer.php'; ?>

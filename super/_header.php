<?php require_super_admin(); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle ?? 'Super Admin') ?> · Coffee Multivendor</title>
    <link rel="stylesheet" href="/assets/css/super-admin.css?v=20260902-3">
</head>
<body>
<header class="topbar">
    <a href="/super/">Coffee Multivendor · Super Admin</a>
    <nav class="actions">
        <span class="muted"><?= e($_SESSION['user_name'] ?? '') ?></span>
        <a href="/super/logout.php">Log out</a>
    </nav>
</header>
<main class="container">

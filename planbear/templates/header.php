<?php
// Common HTML header and navigation
// Expects: $page_title (optional), $active_nav (optional)
$page_title = $page_title ?? APP_NAME;
$user = current_user();
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($page_title) ?> &mdash; <?= h(APP_NAME) ?></title>
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- PlanBär custom CSS -->
    <link rel="stylesheet" href="/assets/css/planbear.css">
</head>
<body>
<?php if ($user): ?>
<nav class="navbar navbar-expand-lg pb-navbar">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="/dashboard.php">
            🐻 <?= h(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'dashboard' ? 'active' : '' ?>"
                       href="/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'mitarbeiter' ? 'active' : '' ?>"
                       href="/mitarbeiter.php">
                        <i class="bi bi-people-fill"></i> Mitarbeiter
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'planung' ? 'active' : '' ?>"
                       href="/planung.php">
                        <i class="bi bi-calendar-week"></i> Planung
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'kalender' ? 'active' : '' ?>"
                       href="/kalender.php">
                        <i class="bi bi-calendar3"></i> Kalender
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'ferien' ? 'active' : '' ?>"
                       href="/ferien.php">
                        <i class="bi bi-sun-fill"></i> Ferien
                    </a>
                </li>
                <?php if (has_role('editor','admin')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'schichten' ? 'active' : '' ?>"
                       href="/schichten.php">
                        <i class="bi bi-clock-history"></i> Schichten
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'urlaub' ? 'active' : '' ?>"
                       href="/urlaub.php">
                        <i class="bi bi-umbrella-fill"></i> Urlaub
                    </a>
                </li>
                <?php if (has_role('admin')): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'benutzer' ? 'active' : '' ?>"
                       href="/benutzer.php">
                        <i class="bi bi-shield-person"></i> Benutzer
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($active_nav ?? '') === 'einstellungen' ? 'active' : '' ?>"
                       href="/einstellungen.php">
                        <i class="bi bi-gear-fill"></i> Einstellungen
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?= h($user['username']) ?>
                        <span class="badge pb-role-badge"><?= h(ucfirst($user['role'])) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small">Angemeldet als <strong><?= h($user['username']) ?></strong></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="/logout.php" class="d-inline">
                                <?= csrf_input() ?>
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right"></i> Abmelden
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>
<main class="container-fluid py-4">
    <?= render_flash() ?>
</main>
<!-- Main content starts below -->
<div class="container-fluid px-4">

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> · api-forge</title>
    <link rel="stylesheet" href="/assets/bootstrap.min.css">
<?php if (!empty($editor)): ?>    <link rel="stylesheet" href="/assets/editor.css">
<?php endif ?></head>
<body class="bg-body-tertiary">
<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="/">api-forge</a>
        <span class="navbar-text small">local interface · nothing leaves your machine</span>
    </div>
</nav>
<main class="container pb-5">
<?= $content ?>
</main>
<?php if (!empty($editor)): ?>
<script src="/assets/ace/ace.js"></script>
<script src="/assets/editor.js" data-token="<?= $e($token) ?>"></script>
<?php endif ?>
</body>
</html>

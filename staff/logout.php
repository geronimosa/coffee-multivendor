<?php
declare(strict_types=1);require_once __DIR__.'/../includes/bootstrap.php';$slug=trim((string)($_GET['slug']??''));unset($_SESSION['staff_user_id'],$_SESSION['staff_vendor_id']);session_regenerate_id(true);redirect('/vendor/'.rawurlencode($slug));

<?php
// 로그아웃: 세션을 지우고 메인 화면으로
require 'db.php';
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;

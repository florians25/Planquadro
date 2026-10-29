<?php session_start(); $_SESSION=[]; session_destroy(); header('Location: ./?logged_out=1'); exit;

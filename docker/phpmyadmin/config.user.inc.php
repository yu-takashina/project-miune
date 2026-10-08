<?php
/**
 * phpMyAdmin ログインセッションを1日間維持する
 * （デフォルトは1440秒＝24分）
 */
$sessionDuration = 86400; // 1日（秒）

$cfg['LoginCookieValidity'] = $sessionDuration;
$cfg['LoginCookieStore'] = $sessionDuration;

// LoginCookieValidity 以上にしないと、PHP側で先にセッションが破棄される
ini_set('session.gc_maxlifetime', (string) $sessionDuration);

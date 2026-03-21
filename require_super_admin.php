<?php
require_once __DIR__ . "/require_admin_or_super_admin.php";

if (($AUTH_USER["role"] ?? "") !== "super_admin") {
  auth_out(403, [
    "ok" => false,
    "message" => "Super admin access required"
  ]);
}
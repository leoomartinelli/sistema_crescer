<?php
// gerar_hash.php
$senhaEmTextoPuro = 'edusef2025';
$hash = password_hash($senhaEmTextoPuro, PASSWORD_BCRYPT);
echo $hash;
?>
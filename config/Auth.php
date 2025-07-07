<?php
// config/Auth.php

// Chave secreta para assinar e verificar JWTs.
// MUITO IMPORTANTE: Mude esta chave para uma string longa e aleatória em produção!
// Você pode gerar uma string aleatória com PHP: bin2hex(random_bytes(32))
define('JWT_SECRET_KEY', '6b3866fd4232f4430a01b4ae0687eec6366fd8f670289db883339cfda5515275');

// Algoritmo de criptografia para o JWT (HS256 é comum)
define('JWT_ALGORITHM', 'HS256');

// Tempo de expiração do token em segundos (ex: 1 hora = 3600 segundos)
define('JWT_EXPIRATION_TIME', 3600);

<?php
// config/Database.php
class Database {
    private $host = "localhost";
    private $db_name = "sistema_crescer3"; // Nome do banco de dados
    private $username = "martinelli"; // Seu usuário do banco de dados
    private $password = "@Leodan1"; // Sua senha do banco de dados
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
        } catch(PDOException $exception) {
            echo "Erro de conexão: " . $exception->getMessage();
        }
        return $this->conn;
    }
}
?>
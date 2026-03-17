<?php
// controllers/AuthController.php
require_once __DIR__ . '/../utils/Response.php';

class AuthController {
    private PDO $db;
    public function __construct(PDO $db) { $this->db = $db; }

    // POST /auth/registro
    // Body: { nombre_usuario, correo, telefono, dni, contrasena, referido_por? }
    // Response: { success, message, id }
    public function registro(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];

        foreach (['nombre_usuario','correo','telefono','dni','contrasena'] as $f) {
            if (empty($d[$f])) Response::error("El campo '$f' es requerido", 400);
        }

        $nombre   = trim($d['nombre_usuario']);
        $correo   = trim(strtolower($d['correo']));
        $telefono = trim($d['telefono']);
        $dni      = trim($d['dni']);
        $pass     = $d['contrasena'];
        $referido = (isset($d['referido_por']) && intval($d['referido_por']) > 0)
                    ? intval($d['referido_por']) : null;

        if (strlen($dni) !== 8 || !ctype_digit($dni))
            Response::error("El DNI debe tener exactamente 8 dígitos", 400);

        try {
            // Verificar duplicados
            foreach ([
                'correo'         => [$correo,    'El correo'],
                'dni'            => [$dni,        'El DNI'],
                'nombre_usuario' => [$nombre,     'El nombre de usuario'],
                'telefono'       => [$telefono,   'El teléfono'],
            ] as $col => [$val, $label]) {
                $s = $this->db->prepare("SELECT id FROM usuarios WHERE $col = ?");
                $s->execute([$val]);
                if ($s->fetch()) Response::error("$label ya está registrado", 409);
            }

            // Verificar que el referido existe
            if ($referido !== null) {
                $sr = $this->db->prepare("SELECT id FROM usuarios WHERE id = ?");
                $sr->execute([$referido]);
                if (!$sr->fetch()) $referido = null;
            }

            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $this->db->prepare("
                INSERT INTO usuarios (nombre_usuario, correo, telefono, dni, contrasena, referido_por)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $correo, $telefono, $dni, $hash, $referido]);

            Response::json(['success' => true, 'message' => 'Usuario registrado correctamente', 'id' => (int)$this->db->lastInsertId()], 201);

        } catch (PDOException $e) {
            Response::error("Error al registrar: " . $e->getMessage(), 500);
        }
    }

    // POST /auth/login
    // Body: { correo, contrasena }
    // Response: { success, message, usuario: {id,nombre_usuario,correo,telefono,dni,saldo,referido_por} }
    public function login(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['correo']) || empty($d['contrasena']))
            Response::error("Correo y contraseña son requeridos", 400);

        try {
            $stmt = $this->db->prepare("
                SELECT id, nombre_usuario, correo, telefono, dni, contrasena, saldo, referido_por
                FROM usuarios WHERE correo = ?
            ");
            $stmt->execute([trim(strtolower($d['correo']))]);
            $u = $stmt->fetch();

            if (!$u || !password_verify($d['contrasena'], $u['contrasena']))
                Response::error("Correo o contraseña incorrectos", 401);

            unset($u['contrasena']);
            $u['id']    = (int)$u['id'];
            $u['saldo'] = (float)$u['saldo'];
            Response::json(['success' => true, 'usuario' => $u]);

        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // POST /auth/recuperar
    // Body: { correo, dni }
    // Response: { success, usuario_id }
    public function recuperar(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['correo']) || empty($d['dni']))
            Response::error("Correo y DNI son requeridos", 400);

        try {
            $stmt = $this->db->prepare("SELECT id FROM usuarios WHERE correo = ? AND dni = ?");
            $stmt->execute([trim(strtolower($d['correo'])), trim($d['dni'])]);
            $u = $stmt->fetch();

            if (!$u) Response::error("No se encontró una cuenta con esos datos", 404);
            Response::json(['success' => true, 'usuario_id' => (int)$u['id']]);

        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // POST /auth/nueva-contrasena
    // Body: { usuario_id, nueva_contrasena }
    // Response: { success, message }
    public function nuevaContrasena(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['usuario_id']) || empty($d['nueva_contrasena']))
            Response::error("Faltan campos requeridos", 400);
        if (strlen($d['nueva_contrasena']) < 6)
            Response::error("La contraseña debe tener al menos 6 caracteres", 400);

        try {
            $hash = password_hash($d['nueva_contrasena'], PASSWORD_BCRYPT);
            $this->db->prepare("UPDATE usuarios SET contrasena = ? WHERE id = ?")
                     ->execute([$hash, intval($d['usuario_id'])]);
            Response::json(['success' => true, 'message' => 'Contraseña actualizada correctamente']);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // GET /auth/cuentas
    // Response: { success, data: [{id, nombre_usuario, correo}] }
    public function getCuentas(): void {
        try {
            $stmt = $this->db->query("SELECT id, nombre_usuario, correo FROM usuarios ORDER BY nombre_usuario ASC");
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            Response::json(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}

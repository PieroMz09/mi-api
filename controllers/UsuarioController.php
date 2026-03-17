<?php
// controllers/UsuarioController.php
require_once __DIR__ . '/../utils/Response.php';

class UsuarioController {
    private PDO $db;
    public function __construct(PDO $db) { $this->db = $db; }

    // GET /usuario/{id}
    // Response: { success, usuario: {id,nombre_usuario,correo,telefono,dni,saldo,referido_por} }
    public function getPerfil($id): void {
        $id = intval($id);
        try {
            $stmt = $this->db->prepare("
                SELECT id, nombre_usuario, correo, telefono, dni, saldo, referido_por
                FROM usuarios WHERE id = ?
            ");
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if (!$u) Response::error("Usuario no encontrado", 404);
            $u['id']    = (int)$u['id'];
            $u['saldo'] = (float)$u['saldo'];
            Response::json(['success' => true, 'usuario' => $u]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // PUT /usuario/{id}
    // Body: { nombre_usuario?, correo?, telefono? }
    // Response: { success, message, usuario }
    public function editarPerfil($id): void {
        $id = intval($id);
        $d  = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            $campos = []; $vals = [];

            if (!empty($d['nombre_usuario'])) {
                $s = $this->db->prepare("SELECT id FROM usuarios WHERE nombre_usuario = ? AND id != ?");
                $s->execute([trim($d['nombre_usuario']), $id]);
                if ($s->fetch()) Response::error("El nombre de usuario ya está en uso", 409);
                $campos[] = "nombre_usuario = ?"; $vals[] = trim($d['nombre_usuario']);
            }
            if (!empty($d['correo'])) {
                $s = $this->db->prepare("SELECT id FROM usuarios WHERE correo = ? AND id != ?");
                $s->execute([trim(strtolower($d['correo'])), $id]);
                if ($s->fetch()) Response::error("El correo ya está en uso", 409);
                $campos[] = "correo = ?"; $vals[] = trim(strtolower($d['correo']));
            }
            if (!empty($d['telefono'])) {
                $campos[] = "telefono = ?"; $vals[] = trim($d['telefono']);
            }
            if (empty($campos)) Response::error("No hay campos para actualizar", 400);

            $vals[] = $id;
            $this->db->prepare("UPDATE usuarios SET " . implode(', ', $campos) . " WHERE id = ?")
                     ->execute($vals);

            $s2 = $this->db->prepare("SELECT id, nombre_usuario, correo, telefono, dni, saldo, referido_por FROM usuarios WHERE id = ?");
            $s2->execute([$id]);
            $u = $s2->fetch();
            $u['id'] = (int)$u['id']; $u['saldo'] = (float)$u['saldo'];
            Response::json(['success' => true, 'message' => 'Perfil actualizado', 'usuario' => $u]);

        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // GET /usuario/{id}/equipo
    // Response: { success, equipo: [{id,nombre_usuario,correo,afiliados:[...]}], total_n1, total_n2, total_n3 }
    public function getEquipo($id): void {
        $id = intval($id);
        try {
            $n1 = $this->getAfiliados($id);
            $tn1 = count($n1); $tn2 = 0; $tn3 = 0;

            foreach ($n1 as &$a1) {
                $a1['afiliados'] = $this->getAfiliados($a1['id']);
                $tn2 += count($a1['afiliados']);
                foreach ($a1['afiliados'] as &$a2) {
                    $a2['afiliados'] = $this->getAfiliados($a2['id']);
                    $tn3 += count($a2['afiliados']);
                }
            }

            Response::json([
                'success'  => true,
                'equipo'   => $n1,
                'total_n1' => $tn1,
                'total_n2' => $tn2,
                'total_n3' => $tn3,
            ]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    private function getAfiliados(int $ref): array {
        $s = $this->db->prepare("SELECT id, nombre_usuario, correo FROM usuarios WHERE referido_por = ?");
        $s->execute([$ref]);
        $rows = $s->fetchAll();
        foreach ($rows as &$r) $r['id'] = (int)$r['id'];
        return $rows;
    }

    // POST /usuario/{id}/afiliar
    // Body: { correo_afiliado }
    // Response: { success, message }
    public function afiliarExistente($id): void {
        $id = intval($id);
        $d  = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['correo_afiliado'])) Response::error("Se requiere el correo del afiliado", 400);

        try {
            $s = $this->db->prepare("SELECT id FROM usuarios WHERE correo = ?");
            $s->execute([trim(strtolower($d['correo_afiliado']))]);
            $af = $s->fetch();
            if (!$af) Response::error("No se encontró el usuario", 404);

            $this->db->prepare("UPDATE usuarios SET referido_por = ? WHERE id = ? AND referido_por IS NULL")
                     ->execute([$id, $af['id']]);
            Response::json(['success' => true, 'message' => 'Afiliado vinculado correctamente']);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}
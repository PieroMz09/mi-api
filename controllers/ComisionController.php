<?php
// controllers/ComisionController.php
require_once __DIR__ . '/../utils/Response.php';

class ComisionController {
    private PDO $db;
    private const PX = 10; // por página

    public function __construct(PDO $db) { $this->db = $db; }

    // GET /comision/saldo/{uid}
    // Response: { success, saldo }
    public function getSaldo($uid): void {
        $uid = intval($uid);
        try {
            $s = $this->db->prepare("SELECT saldo FROM usuarios WHERE id=?");
            $s->execute([$uid]);
            $v = $s->fetchColumn();
            if ($v === false) Response::error("Usuario no encontrado", 404);
            Response::json(['success' => true, 'saldo' => (float)$v]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // POST /comision/recargar
    // Body: { usuario_id, monto, metodo_pago }
    // Response: { success, message }
    public function recargar(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['usuario_id']) || empty($d['monto']) || empty($d['metodo_pago']))
            Response::error("Faltan campos: usuario_id, monto, metodo_pago", 400);

        $uid   = intval($d['usuario_id']);
        $monto = floatval($d['monto']);
        if ($monto <= 0) Response::error("El monto debe ser mayor a 0", 400);

        try {
            $this->db->prepare("
                INSERT INTO recargas (usuario_id, monto, metodo_pago, estado, fecha)
                VALUES (?, ?, ?, 'pendiente', NOW())
            ")->execute([$uid, $monto, trim($d['metodo_pago'])]);

            Response::json(['success' => true, 'message' => 'Solicitud de recarga enviada. Pendiente de aprobación.']);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // GET /comision/recargas/{uid}?page=1
    // Response: { success, data:[{id,monto,metodo_pago,estado,fecha}], ultima_pagina }
    public function getRecargas($uid): void {
        $uid    = intval($uid);
        $pagina = max(1, intval($_GET['page'] ?? 1));
        $offset = ($pagina - 1) * self::PX;
        try {
            $tc = $this->db->prepare("SELECT COUNT(*) FROM recargas WHERE usuario_id=?");
            $tc->execute([$uid]);
            $total    = intval($tc->fetchColumn());
            $ult_pag  = max(1, (int)ceil($total / self::PX));

            $stmt = $this->db->prepare("
                SELECT id, monto, metodo_pago, estado,
                       DATE_FORMAT(fecha, '%d/%m/%Y - %h:%i %p') AS fecha
                FROM recargas WHERE usuario_id=?
                ORDER BY fecha DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$uid, self::PX, $offset]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['monto'] = (float)$r['monto']; }

            Response::json(['success' => true, 'data' => $rows, 'ultima_pagina' => $ult_pag, 'total' => $total]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // POST /comision/retirar
    // Body: { usuario_id, monto, tarjeta_id, nota? }
    // Response: { success, message }
    public function retirar(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['usuario_id']) || empty($d['monto']) || empty($d['tarjeta_id']))
            Response::error("Faltan campos: usuario_id, monto, tarjeta_id", 400);

        $uid     = intval($d['usuario_id']);
        $monto   = floatval($d['monto']);
        $tarjeta = intval($d['tarjeta_id']);
        if ($monto <= 0) Response::error("El monto debe ser mayor a 0", 400);

        try {
            // Verificar saldo
            $ss = $this->db->prepare("SELECT saldo FROM usuarios WHERE id=?");
            $ss->execute([$uid]);
            $saldo = floatval($ss->fetchColumn());
            if ($saldo < $monto) Response::error("Saldo insuficiente. Tienes S/." . number_format($saldo, 2), 400);

            // Verificar tarjeta
            $st = $this->db->prepare("SELECT id FROM tarjetas WHERE id=? AND usuario_id=?");
            $st->execute([$tarjeta, $uid]);
            if (!$st->fetch()) Response::error("Tarjeta no válida", 400);

            $this->db->prepare("INSERT INTO retiros (usuario_id, monto, tarjeta_id, estado, fecha) VALUES (?, ?, ?, 'pendiente', NOW())")
                     ->execute([$uid, $monto, $tarjeta]);

            // Descontar saldo inmediatamente
            $this->db->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id=?")->execute([$monto, $uid]);

            Response::json(['success' => true, 'message' => 'Solicitud de retiro enviada. Pendiente de procesamiento.']);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // GET /comision/retiros/{uid}?page=1
    // Response: { success, data:[{id,monto,estado,fecha,banco,numero_cuenta}], ultima_pagina }
    public function getRetiros($uid): void {
        $uid    = intval($uid);
        $pagina = max(1, intval($_GET['page'] ?? 1));
        $offset = ($pagina - 1) * self::PX;
        try {
            $tc = $this->db->prepare("SELECT COUNT(*) FROM retiros WHERE usuario_id=?");
            $tc->execute([$uid]);
            $total   = intval($tc->fetchColumn());
            $ult_pag = max(1, (int)ceil($total / self::PX));

            $stmt = $this->db->prepare("
                SELECT r.id, r.monto, r.estado,
                       DATE_FORMAT(r.fecha, '%d/%m/%Y - %h:%i %p') AS fecha,
                       t.banco, t.numero_cuenta
                FROM retiros r
                JOIN tarjetas t ON t.id = r.tarjeta_id
                WHERE r.usuario_id=?
                ORDER BY r.fecha DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$uid, self::PX, $offset]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['monto'] = (float)$r['monto']; }

            Response::json(['success' => true, 'data' => $rows, 'ultima_pagina' => $ult_pag, 'total' => $total]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // GET /comision/movimientos/{uid}?page=1
    // Response: { success, data:[{id,monto,nivel,concepto,fecha}], ultima_pagina }
    public function getMovimientos($uid): void {
        $uid    = intval($uid);
        $pagina = max(1, intval($_GET['page'] ?? 1));
        $offset = ($pagina - 1) * self::PX;
        try {
            $tc = $this->db->prepare("SELECT COUNT(*) FROM movimientos WHERE usuario_id=? AND tipo='comision'");
            $tc->execute([$uid]);
            $total   = intval($tc->fetchColumn());
            $ult_pag = max(1, (int)ceil($total / self::PX));

            $stmt = $this->db->prepare("
                SELECT id, monto, concepto,
                       DATE_FORMAT(fecha, '%d/%m/%Y - %h:%i %p') AS fecha
                FROM movimientos WHERE usuario_id=? AND tipo='comision'
                ORDER BY fecha DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$uid, self::PX, $offset]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['id']    = (int)$r['id'];
                $r['monto'] = (float)$r['monto'];
                // Extraer nivel del concepto "Comision Nivel 1 ..."
                preg_match('/Nivel\s*(\d)/i', $r['concepto'], $m);
                $r['nivel'] = isset($m[1]) ? (int)$m[1] : 0;
            }

            Response::json(['success' => true, 'data' => $rows, 'ultima_pagina' => $ult_pag, 'total' => $total]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // ─── TARJETAS ────────────────────────────────────────────────

    // POST /tarjeta/agregar
    // Body: { usuario_id, banco, numero_cuenta, cci }
    // Response: { success, message }
    public function agregarTarjeta(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['usuario_id']) || empty($d['banco']) || empty($d['numero_cuenta']) || empty($d['cci']))
            Response::error("Faltan campos requeridos", 400);

        try {
            $this->db->prepare("INSERT INTO tarjetas (usuario_id, banco, numero_cuenta, cci) VALUES (?, ?, ?, ?)")
                     ->execute([intval($d['usuario_id']), trim($d['banco']), trim($d['numero_cuenta']), trim($d['cci'])]);
            Response::json(['success' => true, 'message' => 'Tarjeta agregada correctamente'], 201);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // GET /tarjeta/{uid}
    // Response: { success, data: [{id,banco,numero_cuenta,cci}] }
    public function getTarjetas($uid): void {
        $uid = intval($uid);
        try {
            $stmt = $this->db->prepare("SELECT id, banco, numero_cuenta, cci FROM tarjetas WHERE usuario_id=? ORDER BY id ASC");
            $stmt->execute([$uid]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            Response::json(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // PUT /tarjeta/{id}
    // Body: { banco?, numero_cuenta?, cci? }
    // Response: { success, message }
    public function editarTarjeta($id): void {
        $id = intval($id);
        $d  = json_decode(file_get_contents('php://input'), true) ?? [];
        $campos = []; $vals = [];
        if (!empty($d['banco']))         { $campos[] = "banco=?";         $vals[] = trim($d['banco']); }
        if (!empty($d['numero_cuenta'])) { $campos[] = "numero_cuenta=?"; $vals[] = trim($d['numero_cuenta']); }
        if (!empty($d['cci']))           { $campos[] = "cci=?";           $vals[] = trim($d['cci']); }
        if (empty($campos)) Response::error("Sin campos para actualizar", 400);
        $vals[] = $id;
        try {
            $this->db->prepare("UPDATE tarjetas SET " . implode(',', $campos) . " WHERE id=?")->execute($vals);
            Response::json(['success' => true, 'message' => 'Tarjeta actualizada']);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    // DELETE /tarjeta/{id}
    // Response: { success, message }
    public function eliminarTarjeta($id): void {
        try {
            $this->db->prepare("DELETE FROM tarjetas WHERE id=?")->execute([intval($id)]);
            Response::json(['success' => true, 'message' => 'Tarjeta eliminada']);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }
}

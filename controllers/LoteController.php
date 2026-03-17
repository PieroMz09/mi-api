<?php
// controllers/LoteController.php
require_once __DIR__ . '/../utils/Response.php';

class LoteController {
    private PDO $db;
    private const RESERVA = 500.00;

    public function __construct(PDO $db) { $this->db = $db; }

    public function reservar(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['usuario_id']) || empty($d['lote_id']))
            Response::error("Faltan campos requeridos", 400);

        $uid = intval($d['usuario_id']);
        $lid = intval($d['lote_id']);

        try {
            $this->db->beginTransaction();

            $ss = $this->db->prepare("SELECT saldo FROM usuarios WHERE id = ?");
            $ss->execute([$uid]);
            $saldo = floatval($ss->fetchColumn());
            if ($saldo < self::RESERVA) {
                $this->db->rollBack();
                Response::error("Saldo insuficiente. Necesitas S/.500.00. Tienes S/." . number_format($saldo, 2), 400);
            }

            $sl = $this->db->prepare("
                SELECT l.id, l.estado::TEXT AS estado, l.codigo,
                       s.precio, s.nombre AS seccion, p.nombre AS proyecto
                FROM lotes l
                JOIN secciones s ON s.id = l.seccion_id
                JOIN proyectos p ON p.id = s.proyecto_id
                WHERE l.id = ?
            ");
            $sl->execute([$lid]);
            $lote = $sl->fetch();

            if (!$lote)                           { $this->db->rollBack(); Response::error("Lote no encontrado", 404); }
            if ($lote['estado'] !== 'disponible') { $this->db->rollBack(); Response::error("El lote {$lote['codigo']} no está disponible", 400); }

            $this->db->prepare("UPDATE lotes SET estado='reservado', reservado_por=?, fecha_reserva=NOW() WHERE id=?")
                     ->execute([$uid, $lid]);
            $this->db->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id=?")->execute([self::RESERVA, $uid]);

            $concepto = "Separacion lote {$lote['codigo']} - {$lote['proyecto']} Seccion {$lote['seccion']}";
            $this->db->prepare("INSERT INTO movimientos (usuario_id, tipo, monto, concepto, fecha) VALUES (?, 'reserva', ?, ?, NOW())")
                     ->execute([$uid, -self::RESERVA, $concepto]);

            $this->comisiones($uid, self::RESERVA, "reserva lote {$lote['codigo']}");

            $sn = $this->db->prepare("SELECT saldo FROM usuarios WHERE id=?");
            $sn->execute([$uid]);
            $nuevo = floatval($sn->fetchColumn());

            $this->db->commit();
            Response::json(['success' => true, 'message' => "Lote {$lote['codigo']} separado correctamente", 'nuevo_saldo' => $nuevo]);

        } catch (PDOException $e) {
            $this->db->rollBack();
            Response::error("Error al reservar: " . $e->getMessage(), 500);
        }
    }

    public function comprar(): void {
        $d = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($d['usuario_id']) || empty($d['lote_id']))
            Response::error("Faltan campos requeridos", 400);

        $uid = intval($d['usuario_id']);
        $lid = intval($d['lote_id']);

        try {
            $this->db->beginTransaction();

            $sl = $this->db->prepare("
                SELECT l.id, l.estado::TEXT AS estado, l.codigo, l.reservado_por,
                       s.precio, s.nombre AS seccion, p.nombre AS proyecto
                FROM lotes l
                JOIN secciones s ON s.id = l.seccion_id
                JOIN proyectos p ON p.id = s.proyecto_id
                WHERE l.id = ?
            ");
            $sl->execute([$lid]);
            $lote = $sl->fetch();

            if (!$lote)                                  { $this->db->rollBack(); Response::error("Lote no encontrado", 404); }
            if ($lote['estado'] !== 'reservado')         { $this->db->rollBack(); Response::error("Solo puedes comprar lotes que hayas separado", 400); }
            if (intval($lote['reservado_por']) !== $uid) { $this->db->rollBack(); Response::error("Este lote no fue separado por ti", 403); }

            $precio   = floatval($lote['precio']);
            $restante = $precio - self::RESERVA;

            $ss = $this->db->prepare("SELECT saldo FROM usuarios WHERE id=?");
            $ss->execute([$uid]);
            $saldo = floatval($ss->fetchColumn());

            if ($saldo < $restante) {
                $this->db->rollBack();
                Response::error("Saldo insuficiente. Necesitas S/." . number_format($restante, 2) . ". Tienes S/." . number_format($saldo, 2), 400);
            }

            $this->db->prepare("UPDATE lotes SET estado='vendido', comprado_por=?, fecha_compra=NOW() WHERE id=?")
                     ->execute([$uid, $lid]);
            $this->db->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id=?")->execute([$restante, $uid]);
            $this->db->prepare("INSERT INTO compras (usuario_id, lote_id, monto, fecha) VALUES (?, ?, ?, NOW())")
                     ->execute([$uid, $lid, $precio]);

            $concepto = "Compra lote {$lote['codigo']} - {$lote['proyecto']} Seccion {$lote['seccion']}";
            $this->db->prepare("INSERT INTO movimientos (usuario_id, tipo, monto, concepto, fecha) VALUES (?, 'compra', ?, ?, NOW())")
                     ->execute([$uid, -$restante, $concepto]);

            $this->comisiones($uid, $restante, "compra lote {$lote['codigo']}");

            $sn = $this->db->prepare("SELECT saldo FROM usuarios WHERE id=?");
            $sn->execute([$uid]);
            $nuevo = floatval($sn->fetchColumn());

            $this->db->commit();
            Response::json([
                'success'      => true,
                'message'      => "Lote {$lote['codigo']} comprado exitosamente",
                'precio_total' => $precio,
                'monto_pagado' => $restante,
                'nuevo_saldo'  => $nuevo,
            ]);

        } catch (PDOException $e) {
            $this->db->rollBack();
            Response::error("Error al comprar: " . $e->getMessage(), 500);
        }
    }

    public function getReservados($uid): void {
        $uid = intval($uid);
        try {
            $stmt = $this->db->prepare("
                SELECT l.id, l.codigo, l.estado::TEXT AS estado,
                       CAST(l.area_m2 AS TEXT) AS area_m2,
                       TO_CHAR(l.fecha_reserva, 'DD/MM/YYYY') AS fecha_reserva,
                       l.reservado_por,
                       s.nombre AS seccion, s.precio,
                       p.id AS proyecto_id, p.nombre AS proyecto
                FROM lotes l
                JOIN secciones s ON s.id = l.seccion_id
                JOIN proyectos p ON p.id = s.proyecto_id
                WHERE l.reservado_por = ? AND l.estado = 'reservado'
                ORDER BY l.fecha_reserva DESC
            ");
            $stmt->execute([$uid]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['id']            = (int)$r['id'];
                $r['precio']        = (float)$r['precio'];
                $r['reservado_por'] = (int)$r['reservado_por'];
                $r['proyecto_id']   = (int)$r['proyecto_id'];
            }
            Response::json(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function getComprados($uid): void {
        $uid = intval($uid);
        try {
            $stmt = $this->db->prepare("
                SELECT l.id, l.codigo, l.estado::TEXT AS estado,
                       CAST(l.area_m2 AS TEXT) AS area_m2,
                       TO_CHAR(l.fecha_compra, 'DD/MM/YYYY') AS fecha_compra,
                       s.nombre AS seccion, s.precio,
                       p.id AS proyecto_id, p.nombre AS proyecto,
                       c.monto AS monto_pagado
                FROM lotes l
                JOIN secciones s ON s.id = l.seccion_id
                JOIN proyectos p ON p.id = s.proyecto_id
                JOIN compras c ON c.lote_id = l.id AND c.usuario_id = ?
                WHERE l.comprado_por = ?
                ORDER BY c.fecha DESC
            ");
            $stmt->execute([$uid, $uid]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['id']           = (int)$r['id'];
                $r['precio']       = (float)$r['precio'];
                $r['proyecto_id']  = (int)$r['proyecto_id'];
                $r['monto_pagado'] = (float)$r['monto_pagado'];
            }
            Response::json(['success' => true, 'data' => $rows]);
        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    private function comisiones(int $uid, float $base, string $origen): void {
        $pcts      = [1 => 0.15, 2 => 0.05, 3 => 0.03];
        $actual_id = $uid;
        for ($n = 1; $n <= 3; $n++) {
            $s = $this->db->prepare("SELECT referido_por FROM usuarios WHERE id=?");
            $s->execute([$actual_id]);
            $up = $s->fetchColumn();
            if (!$up) break;

            $com = round($base * $pcts[$n], 2);
            $pct = $pcts[$n] * 100;

            $this->db->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id=?")->execute([$com, $up]);
            $this->db->prepare("
                INSERT INTO movimientos (usuario_id, tipo, monto, concepto, referencia_usuario_id, fecha)
                VALUES (?, 'comision', ?, ?, ?, NOW())
            ")->execute([$up, $com, "Comision Nivel $n ({$pct}%) por $origen", $uid]);

            $actual_id = $up;
        }
    }
}

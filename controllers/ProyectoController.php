<?php
// controllers/ProyectoController.php
require_once __DIR__ . '/../utils/Response.php';

class ProyectoController {
    private PDO $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function getAll(): void {
        try {
            $stmt = $this->db->query("SELECT id, nombre, descripcion, imagen FROM proyectos ORDER BY id ASC");
            $proyectos = $stmt->fetchAll();

            foreach ($proyectos as &$p) {
                $p['id']        = (int)$p['id'];
                $p['ubicacion'] = $this->extraerUbicacion($p['nombre']);
                $p['secciones'] = $this->getSecciones((int)$p['id']);
            }

            Response::json(['success' => true, 'data' => $proyectos]);

        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function getDetalle($id): void {
        $id = intval($id);
        try {
            $stmt = $this->db->prepare("SELECT id, nombre, descripcion, imagen FROM proyectos WHERE id = ?");
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) Response::error("Proyecto no encontrado", 404);

            $p['id']        = (int)$p['id'];
            $p['ubicacion'] = $this->extraerUbicacion($p['nombre']);
            $p['secciones'] = $this->getSeccionesConPrecio((int)$p['id']);

            Response::json(['success' => true, 'proyecto' => $p]);

        } catch (PDOException $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    private function getSecciones(int $proyectoId): array {
        $s = $this->db->prepare("SELECT id, nombre FROM secciones WHERE proyecto_id = ? ORDER BY nombre ASC");
        $s->execute([$proyectoId]);
        $secs = $s->fetchAll();
        foreach ($secs as &$sec) {
            $sec['id']    = (int)$sec['id'];
            $sec['lotes'] = $this->getLotes((int)$sec['id']);
        }
        return $secs;
    }

    private function getSeccionesConPrecio(int $proyectoId): array {
        $s = $this->db->prepare("SELECT id, nombre, precio FROM secciones WHERE proyecto_id = ? ORDER BY nombre ASC");
        $s->execute([$proyectoId]);
        $secs = $s->fetchAll();
        foreach ($secs as &$sec) {
            $sec['id']     = (int)$sec['id'];
            $sec['precio'] = (float)$sec['precio'];
            $sec['lotes']  = $this->getLotes((int)$sec['id']);
        }
        return $secs;
    }

    private function getLotes(int $seccionId): array {
        $s = $this->db->prepare("
            SELECT l.id, l.codigo, l.estado::TEXT AS estado,
                   CAST(l.area_m2 AS TEXT) AS area_m2,
                   l.reservado_por, s.precio,
                   u.nombre_usuario AS reservado_por_nombre
            FROM lotes l
            JOIN secciones s ON s.id = l.seccion_id
            LEFT JOIN usuarios u ON u.id = l.reservado_por
            WHERE l.seccion_id = ?
            ORDER BY l.codigo ASC
        ");
        $s->execute([$seccionId]);
        $lotes = $s->fetchAll();
        foreach ($lotes as &$l) {
            $l['id']            = (int)$l['id'];
            $l['precio']        = (float)$l['precio'];
            $l['reservado_por'] = (int)$l['reservado_por'];
        }
        return $lotes;
    }

    private function extraerUbicacion(string $nombre): string {
        if (strpos($nombre, '-') !== false) {
            $partes = explode('-', $nombre);
            return trim(end($partes));
        }
        return 'Lima';
    }
}
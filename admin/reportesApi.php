<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['admin_id'], $_SESSION['admin_correo'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}
$currentRole = strtoupper((string)($_SESSION['admin_rol'] ?? 'ADMIN'));
$currentUserId = (int)($_SESSION['admin_id'] ?? 0);

$conexion = @new mysqli('localhost', 'root', '', 'rosacocoaBD');
if ($conexion->connect_error) {
    $conexion = @new mysqli('localhost', 'root', '', 'rosacocoa');
}

if ($conexion->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de conexión a BD']);
    exit;
}

$conexion->set_charset('utf8mb4');

function normalizeTipo($category)
{
    $value = strtolower(trim($category));
    if ($value === 'cursos' || $value === 'curso') {
        return 'CURSO';
    }
    if ($value === 'tienda' || $value === 'producto' || $value === 'productos' || $value === 'pedidos') {
        return 'PRODUCTO';
    }
    if ($value === 'otro') {
        return 'OTRO';
    }
    return 'SISTEMA';
}

function normalizePrioridad($severity)
{
    $value = strtolower(trim($severity));
    if ($value === 'baja') {
        return 'BAJA';
    }
    if ($value === 'alta' || $value === 'critica') {
        return 'ALTA';
    }
    return 'MEDIA';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($currentRole === 'CHEF') {
        $stmt = $conexion->prepare(
            "SELECT rp.rep_id, rp.rep_titulo, rp.rep_descripcion, rp.rep_tipo, rp.rep_estado, rp.rep_fecha, rp.rep_prioridad,
                    u.usu_correo, rp.usu_id
             FROM reporte_problema rp
             LEFT JOIN usuario u ON u.usu_id = rp.usu_id
             WHERE rp.usu_id = ?
             ORDER BY rp.rep_fecha DESC, rp.rep_id DESC"
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo preparar consulta']);
            $conexion->close();
            exit;
        }
        $stmt->bind_param('i', $currentUserId);
        $stmt->execute();
        $resultado = $stmt->get_result();
    } else {
        $sql = "SELECT rp.rep_id, rp.rep_titulo, rp.rep_descripcion, rp.rep_tipo, rp.rep_estado, rp.rep_fecha, rp.rep_prioridad,
                       u.usu_correo, rp.usu_id
                FROM reporte_problema rp
                LEFT JOIN usuario u ON u.usu_id = rp.usu_id
                ORDER BY rp.rep_fecha DESC, rp.rep_id DESC";
        $resultado = $conexion->query($sql);
    }

    if (!$resultado) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo leer reportes']);
        $conexion->close();
        exit;
    }

    $rows = [];
    while ($row = $resultado->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['rep_id'],
            'title' => $row['rep_titulo'],
            'description' => $row['rep_descripcion'],
            'category' => strtolower((string)$row['rep_tipo']),
            'severity' => strtolower((string)$row['rep_prioridad']),
            'status' => $row['rep_estado'],
            'createdAt' => $row['rep_fecha'],
            'userEmail' => $row['usu_correo'] ?: $_SESSION['admin_correo'],
            'ownerId' => (int)($row['usu_id'] ?? 0),
        ];
    }
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    $conexion->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $action = trim((string)($body['action'] ?? 'create'));
    if ($action === 'update_status') {
        if ($currentRole !== 'ADMIN') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Solo ADMIN puede cambiar estado']);
            $conexion->close();
            exit;
        }

        $id = (int)($body['id'] ?? 0);
        $status = strtoupper(trim((string)($body['status'] ?? '')));
        $allowedStatus = ['ABIERTO', 'EN_PROCESO', 'CERRADO'];

        if ($id <= 0 || !in_array($status, $allowedStatus, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Parámetros de estado inválidos']);
            $conexion->close();
            exit;
        }

        $stmt = $conexion->prepare('UPDATE reporte_problema SET rep_estado = ? WHERE rep_id = ?');
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo preparar actualización']);
            $conexion->close();
            exit;
        }

        $stmt->bind_param('si', $status, $id);
        $ok = $stmt->execute();
        $stmt->close();
        $conexion->close();

        if (!$ok) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar estado']);
            exit;
        }

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'ID inválido']);
            $conexion->close();
            exit;
        }

        $stmt = $conexion->prepare('SELECT rep_id, rep_estado, usu_id FROM reporte_problema WHERE rep_id = ? LIMIT 1');
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo preparar validación']);
            $conexion->close();
            exit;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $report = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$report) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Reporte no encontrado']);
            $conexion->close();
            exit;
        }

        $ownerId = (int)$report['usu_id'];
        $estado = strtoupper((string)$report['rep_estado']);

        if ($currentRole !== 'ADMIN') {
            if ($ownerId !== $currentUserId) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Solo puedes borrar tus reportes']);
                $conexion->close();
                exit;
            }
            if ($estado === 'CERRADO') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Reporte cerrado: solo ADMIN puede borrarlo']);
                $conexion->close();
                exit;
            }
        }

        $stmt = $conexion->prepare('DELETE FROM reporte_problema WHERE rep_id = ?');
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo preparar borrado']);
            $conexion->close();
            exit;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        $conexion->close();

        if (!$ok) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo borrar el reporte']);
            exit;
        }

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $title = trim((string)($body['title'] ?? ''));
    $description = trim((string)($body['description'] ?? ''));
    $category = trim((string)($body['category'] ?? ''));
    $severity = trim((string)($body['severity'] ?? ''));

    if ($title === '' || $description === '' || $category === '' || $severity === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Faltan campos obligatorios']);
        $conexion->close();
        exit;
    }

    $repTipo = normalizeTipo($category);
    $repPrioridad = normalizePrioridad($severity);
    $usuId = (int)$_SESSION['admin_id'];

    $stmt = $conexion->prepare(
        'INSERT INTO reporte_problema (usu_id, rep_titulo, rep_descripcion, rep_tipo, rep_prioridad, rep_estado) VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo preparar inserción']);
        $conexion->close();
        exit;
    }

    $estado = 'ABIERTO';
    $stmt->bind_param('isssss', $usuId, $title, $description, $repTipo, $repPrioridad, $estado);
    $ok = $stmt->execute();
    $insertId = $stmt->insert_id;
    $stmt->close();
    $conexion->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el reporte']);
        exit;
    }

    echo json_encode(['ok' => true, 'id' => (int)$insertId], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido']);

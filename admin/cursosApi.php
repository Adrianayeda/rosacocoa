<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['admin_id'], $_SESSION['admin_correo'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

$conexion = @new mysqli('localhost', 'root', '', 'rosacocoaBD');
if ($conexion->connect_error) {
    $conexion = @new mysqli('localhost', 'root', '', 'rosacocoa');
}

if ($conexion->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de conexion a BD']);
    exit;
}

$conexion->set_charset('utf8mb4');

function jsonError($message, $statusCode = 422)
{
    http_response_code($statusCode);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
}

function firstExistingValue(array $source, array $keys)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $source)) {
            return $source[$key];
        }
    }
    return null;
}

function quoteIdent($value)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        return null;
    }
    return '`' . $value . '`';
}

function saveUploadedImage(array $file)
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'Archivo de imagen invalido'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Error al subir la imagen'];
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Archivo temporal invalido'];
    }

    $maxBytes = 5 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'La imagen debe pesar maximo 5MB'];
    }

    $originalName = (string)($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        return ['ok' => false, 'error' => 'Formato no permitido. Usa JPG, PNG o WEBP'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'error' => 'El archivo no es una imagen valida'];
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'imgcurso';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'No se pudo crear la carpeta de imagenes'];
    }

    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $random = uniqid('img_', true);
    }
    $fileName = 'curso_' . date('Ymd_His') . '_' . str_replace('.', '', (string)$random) . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen en servidor'];
    }

    return ['ok' => true, 'path' => 'imgcurso/' . $fileName];
}

function tableExists(mysqli $conexion, $table)
{
    $safe = $conexion->real_escape_string((string)$table);
    $res = $conexion->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function deleteStoredFile($relativePath)
{
    $path = trim((string)$relativePath);
    if ($path === '') {
        return;
    }
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return;
    }
    if (strpos($path, '..') !== false) {
        return;
    }

    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $normalized;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function upsertImageCurso(mysqli $conexion, $courseId, $imgPath)
{
    if ($courseId <= 0 || trim((string)$imgPath) === '' || !tableExists($conexion, 'imagen_curso')) {
        return;
    }

    $stmt = $conexion->prepare('SELECT img_cur_id FROM imagen_curso WHERE cur_id = ? AND img_principal = 1 LIMIT 1');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $courseId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row && isset($row['img_cur_id'])) {
        $imgId = (int)$row['img_cur_id'];
        $stmt = $conexion->prepare('UPDATE imagen_curso SET img_url = ? WHERE img_cur_id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $imgPath, $imgId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $stmt = $conexion->prepare('INSERT INTO imagen_curso (cur_id, img_url, img_principal) VALUES (?, ?, 1)');
    if ($stmt) {
        $stmt->bind_param('is', $courseId, $imgPath);
        $stmt->execute();
        $stmt->close();
    }
}

function findCoursesTable(mysqli $conexion)
{
    $candidates = ['curso', 'cursos'];
    foreach ($candidates as $table) {
        $safe = $conexion->real_escape_string((string)$table);
        $res = $conexion->query("SHOW TABLES LIKE '{$safe}'");
        $exists = $res && $res->num_rows > 0;
        if ($exists) {
            return $table;
        }
    }
    return null;
}

function describeTable(mysqli $conexion, $table)
{
    $quotedTable = quoteIdent($table);
    if ($quotedTable === null) {
        return [];
    }

    $rows = [];
    $result = $conexion->query('DESCRIBE ' . $quotedTable);
    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function pickValue(array $row, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function findIdColumn(array $columnMap)
{
    $candidates = ['cur_id', 'curso_id', 'id'];
    foreach ($candidates as $candidate) {
        if (isset($columnMap[$candidate])) {
            return $candidate;
        }
    }
    return null;
}

function normalizeEstadoTexto($value)
{
    $estado = strtoupper(trim((string)$value));
    if ($estado === 'ACTIVO' || $estado === 'PROXIMAMENTE' || $estado === 'COMPLETADO') {
        return 'ACTIVO';
    }
    if ($estado === 'CANCELADO') {
        return 'CANCELADO';
    }
    if ($estado === '1') {
        return 'ACTIVO';
    }
    if ($estado === '0') {
        return 'CANCELADO';
    }
    return 'ACTIVO';
}

function normalizeEstadoTinyint($value)
{
    $estado = normalizeEstadoTexto($value);
    return $estado === 'CANCELADO' ? 0 : 1;
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$table = findCoursesTable($conexion);
if ($table === null) {
    jsonError('No se encontro tabla de cursos (curso/cursos)', 500);
    $conexion->close();
    exit;
}

if ($requestMethod === 'GET') {
    $quotedTable = quoteIdent($table);
    if ($quotedTable === null) {
        jsonError('Nombre de tabla invalido', 500);
        $conexion->close();
        exit;
    }

    $columns = describeTable($conexion, $table);
    if (!$columns) {
        jsonError('No se pudo leer la estructura de la tabla de cursos', 500);
        $conexion->close();
        exit;
    }

    $columnMap = [];
    foreach ($columns as $col) {
        $columnMap[$col['Field']] = true;
    }

    $orderColumn = findIdColumn($columnMap);

    $sql = 'SELECT * FROM ' . $quotedTable;
    if ($orderColumn !== null) {
        $sql .= ' ORDER BY ' . quoteIdent($orderColumn) . ' DESC';
    }
    $sql .= ' LIMIT 100';

    $result = $conexion->query($sql);
    if (!$result) {
        jsonError('No se pudieron cargar cursos', 500);
        $conexion->close();
        exit;
    }

    $imagesMap = [];
    if (tableExists($conexion, 'imagen_curso')) {
        $imgResult = $conexion->query('SELECT cur_id, img_url, img_principal FROM imagen_curso ORDER BY img_principal DESC, img_cur_id DESC');
        if ($imgResult) {
            while ($img = $imgResult->fetch_assoc()) {
                $cid = (int)($img['cur_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                if (!isset($imagesMap[$cid])) {
                    $imagesMap[$cid] = (string)($img['img_url'] ?? '');
                }
            }
        }
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)pickValue($row, ['cur_id', 'curso_id', 'id'], 0);
        $imgFromCourse = (string)pickValue($row, ['cur_imagen', 'imagen', 'imagen_url', 'foto', 'url_imagen'], '');
        $imgPath = $imgFromCourse !== '' ? $imgFromCourse : ($imagesMap[$id] ?? '');

        $rows[] = [
            'id' => $id,
            'nombre' => (string)pickValue($row, ['cur_nombre', 'nombre', 'curso_nombre', 'titulo'], ''),
            'descripcion' => (string)pickValue($row, ['cur_descripcion', 'descripcion', 'detalle'], ''),
            'fechaInicio' => (string)pickValue($row, ['cur_fecha_inicio', 'fecha_inicio', 'inicio', 'cur_fecha'], ''),
            'fechaFin' => (string)pickValue($row, ['cur_fecha_fin', 'fecha_fin', 'fin'], ''),
            'horaInicio' => (string)pickValue($row, ['cur_hora_inicio', 'hora_inicio', 'hora', 'cur_hora'], ''),
            'duracion' => (string)pickValue($row, ['cur_duracion', 'duracion', 'duracion_horas'], ''),
            'lugar' => (string)pickValue($row, ['cur_lugar', 'lugar', 'ubicacion'], ''),
            'cupoMaximo' => (string)pickValue($row, ['cur_cupo_maximo', 'cupo_maximo', 'cupo'], ''),
            'precio' => (string)pickValue($row, ['cur_precio', 'precio', 'costo'], ''),
            'instructor' => (string)pickValue($row, ['cur_instructor', 'instructor', 'docente'], ''),
            'estado' => normalizeEstadoTexto((string)pickValue($row, ['cur_estado', 'estado', 'estatus', 'cur_estatus'], 'ACTIVO')),
            'imagen' => $imgPath,
        ];
    }

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    $conexion->close();
    exit;
}

if ($requestMethod !== 'POST') {
    jsonError('Metodo no permitido', 405);
    $conexion->close();
    exit;
}

$body = $_POST;
$action = strtolower(trim((string)($body['action'] ?? 'create')));

$nombre = trim((string)firstExistingValue($body, ['nombre', 'cur_nombre', 'curso_nombre', 'titulo']));
$descripcion = trim((string)firstExistingValue($body, ['descripcion', 'cur_descripcion', 'detalle']));
$fechaInicio = trim((string)firstExistingValue($body, ['fechaInicio', 'fecha_inicio', 'cur_fecha_inicio']));
$fechaFin = trim((string)firstExistingValue($body, ['fechaFin', 'fecha_fin', 'cur_fecha_fin']));
$horaInicio = trim((string)firstExistingValue($body, ['horaInicio', 'hora_inicio', 'cur_hora_inicio']));
$duracionRaw = firstExistingValue($body, ['duracion', 'cur_duracion', 'duracion_horas']);
$lugar = trim((string)firstExistingValue($body, ['lugar', 'cur_lugar', 'ubicacion']));
$cupoRaw = firstExistingValue($body, ['cupoMaximo', 'cupo_maximo', 'cur_cupo_maximo', 'cupo']);
$precioRaw = firstExistingValue($body, ['precio', 'cur_precio']);
$instructor = trim((string)firstExistingValue($body, ['instructor', 'cur_instructor']));
$estado = strtoupper(trim((string)firstExistingValue($body, ['estado', 'cur_estado', 'estatus'])));
$imagen = trim((string)firstExistingValue($body, ['imagen', 'cur_imagen', 'imagen_url']));

$duracion = is_numeric($duracionRaw) ? (float)$duracionRaw : null;
$cupoMaximo = is_numeric($cupoRaw) ? (int)$cupoRaw : null;
$precio = is_numeric($precioRaw) ? (float)$precioRaw : null;

if (
    $action !== 'delete' && (
    $nombre === '' ||
    $descripcion === '' ||
    $fechaInicio === '' ||
    $fechaFin === '' ||
    $horaInicio === '' ||
    $duracion === null ||
    $lugar === '' ||
    $cupoMaximo === null ||
    $precio === null ||
    $instructor === '' ||
    $estado === ''
    )
) {
    jsonError('Faltan campos obligatorios del curso', 422);
    $conexion->close();
    exit;
}

if ($action !== 'delete' && ($duracion <= 0 || $cupoMaximo <= 0 || $precio < 0)) {
    jsonError('Duracion, cupo y precio deben ser validos', 422);
    $conexion->close();
    exit;
}

if (isset($_FILES['imagen_file']) && is_array($_FILES['imagen_file'])) {
    if ((int)($_FILES['imagen_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = saveUploadedImage($_FILES['imagen_file']);
        if (!$upload['ok']) {
            jsonError((string)$upload['error'], 422);
            $conexion->close();
            exit;
        }
        $imagen = (string)$upload['path'];
    }
}

$columns = describeTable($conexion, $table);
if (!$columns) {
    jsonError('No se pudo leer la estructura de la tabla de cursos', 500);
    $conexion->close();
    exit;
}

$columnMap = [];
foreach ($columns as $col) {
    $columnMap[$col['Field']] = $col;
}
$idColumn = findIdColumn($columnMap);
if ($idColumn === null) {
    jsonError('No se encontro columna ID para cursos', 500);
    $conexion->close();
    exit;
}

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID de curso invalido', 422);
        $conexion->close();
        exit;
    }

    $quotedTable = quoteIdent($table);
    $quotedId = quoteIdent($idColumn);
    if ($quotedTable === null || $quotedId === null) {
        jsonError('Error interno con identificadores SQL', 500);
        $conexion->close();
        exit;
    }

    $pathsToDelete = [];

    $stmt = $conexion->prepare('SELECT * FROM ' . $quotedTable . ' WHERE ' . $quotedId . ' = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $mainImg = (string)pickValue($row, ['cur_imagen', 'imagen', 'imagen_url', 'foto', 'url_imagen'], '');
            if ($mainImg !== '') {
                $pathsToDelete[] = $mainImg;
            }
        }
    }

    if (tableExists($conexion, 'imagen_curso')) {
        $stmt = $conexion->prepare('SELECT img_url FROM imagen_curso WHERE cur_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($img = $res->fetch_assoc()) {
                    $imgPath = trim((string)($img['img_url'] ?? ''));
                    if ($imgPath !== '') {
                        $pathsToDelete[] = $imgPath;
                    }
                }
            }
            $stmt->close();
        }
    }

    $sql = 'DELETE FROM ' . $quotedTable . ' WHERE ' . $quotedId . ' = ? LIMIT 1';
    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        jsonError('No se pudo preparar borrado', 500);
        $conexion->close();
        exit;
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    if ($ok && tableExists($conexion, 'imagen_curso')) {
        $stmt = $conexion->prepare('DELETE FROM imagen_curso WHERE cur_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
    }

    foreach (array_unique($pathsToDelete) as $imgPath) {
        deleteStoredFile($imgPath);
    }

    $conexion->close();

    if (!$ok) {
        jsonError('No se pudo borrar el curso', 500);
        exit;
    }
    if ($affectedRows < 1) {
        jsonError('Curso no encontrado para borrar', 404);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$logicalToCandidates = [
    'nombre' => ['cur_nombre', 'nombre', 'curso_nombre', 'titulo'],
    'descripcion' => ['cur_descripcion', 'descripcion', 'detalle'],
    'fecha_inicio' => ['cur_fecha_inicio', 'fecha_inicio', 'inicio', 'cur_fecha'],
    'fecha_fin' => ['cur_fecha_fin', 'fecha_fin', 'fin'],
    'hora_inicio' => ['cur_hora_inicio', 'hora_inicio', 'hora', 'cur_hora'],
    'duracion' => ['cur_duracion', 'duracion', 'duracion_horas'],
    'lugar' => ['cur_lugar', 'lugar', 'ubicacion'],
    'cupo' => ['cur_cupo_maximo', 'cupo_maximo', 'cupo'],
    'precio' => ['cur_precio', 'precio', 'costo'],
    'instructor' => ['cur_instructor', 'instructor', 'docente'],
    'estado' => ['cur_estado', 'estado', 'estatus', 'cur_estatus'],
    'imagen' => ['cur_imagen', 'imagen', 'imagen_url', 'foto', 'url_imagen'],
    'usuario' => ['usu_id', 'usuario_id', 'creado_por'],
];

$logicalValues = [
    'nombre' => $nombre,
    'descripcion' => $descripcion,
    'fecha_inicio' => $fechaInicio,
    'fecha_fin' => $fechaFin,
    'hora_inicio' => $horaInicio,
    'duracion' => $duracion,
    'lugar' => $lugar,
    'cupo' => $cupoMaximo,
    'precio' => $precio,
    'instructor' => $instructor,
    'estado' => $estado,
    'imagen' => $imagen,
    'usuario' => (int)($_SESSION['admin_id'] ?? 0),
];

$insertColumns = [];
$insertValues = [];

foreach ($logicalToCandidates as $logical => $candidates) {
    $foundColumn = null;
    foreach ($candidates as $candidate) {
        if (isset($columnMap[$candidate])) {
            $foundColumn = $candidate;
            break;
        }
    }
    if ($foundColumn === null) {
        continue;
    }

    $value = $logicalValues[$logical];
    if ($logical === 'estado' && $foundColumn === 'cur_estatus') {
        $value = normalizeEstadoTinyint($value);
    }
    if ($value === '' || $value === null) {
        continue;
    }

    $insertColumns[] = $foundColumn;
    $insertValues[] = $value;
}

if (count($insertColumns) === 0) {
    jsonError('No se pudieron mapear columnas para guardar curso', 500);
    $conexion->close();
    exit;
}

$quotedColumns = [];
foreach ($insertColumns as $col) {
    $quoted = quoteIdent($col);
    if ($quoted === null) {
        jsonError('Nombre de columna invalido detectado', 500);
        $conexion->close();
        exit;
    }
    $quotedColumns[] = $quoted;
}

$quotedTable = quoteIdent($table);
if ($quotedTable === null) {
    jsonError('Nombre de tabla invalido', 500);
    $conexion->close();
    exit;
}

$types = '';
$bindValues = [];
foreach ($insertValues as $value) {
    if (is_int($value)) {
        $types .= 'i';
    } elseif (is_float($value)) {
        $types .= 'd';
    } else {
        $types .= 's';
    }
    $bindValues[] = $value;
}

if ($action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID de curso invalido', 422);
        $conexion->close();
        exit;
    }

    $quotedId = quoteIdent($idColumn);
    if ($quotedId === null) {
        jsonError('Error interno con ID', 500);
        $conexion->close();
        exit;
    }

    $setParts = [];
    foreach ($quotedColumns as $quotedCol) {
        $setParts[] = $quotedCol . ' = ?';
    }
    $sql = 'UPDATE ' . $quotedTable . ' SET ' . implode(', ', $setParts) . ' WHERE ' . $quotedId . ' = ? LIMIT 1';
    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        jsonError('No se pudo preparar actualizacion: ' . $conexion->error, 500);
        $conexion->close();
        exit;
    }
    $types .= 'i';
    $bindValues[] = $id;
    $stmt->bind_param($types, ...$bindValues);
    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        $conexion->close();
        jsonError('No se pudo actualizar curso: ' . $error, 500);
        exit;
    }

    upsertImageCurso($conexion, $id, $imagen);
    $conexion->close();

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'table' => $table,
        'mappedColumns' => $insertColumns,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
$sql = 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $quotedColumns) . ') VALUES (' . $placeholders . ')';
$stmt = $conexion->prepare($sql);
if (!$stmt) {
    jsonError('No se pudo preparar insercion: ' . $conexion->error, 500);
    $conexion->close();
    exit;
}

$stmt->bind_param($types, ...$bindValues);
$ok = $stmt->execute();
$newId = $stmt->insert_id;
$error = $stmt->error;
$stmt->close();

if (!$ok) {
    $conexion->close();
    jsonError('No se pudo guardar curso: ' . $error, 500);
    exit;
}

upsertImageCurso($conexion, (int)$newId, $imagen);
$conexion->close();

echo json_encode([
    'ok' => true,
    'id' => (int)$newId,
    'table' => $table,
    'mappedColumns' => $insertColumns,
], JSON_UNESCAPED_UNICODE);

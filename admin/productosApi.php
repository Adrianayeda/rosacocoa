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

function pickValue(array $row, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return $default;
}

function tableExists(mysqli $conexion, $table)
{
    $safe = $conexion->real_escape_string((string)$table);
    $result = $conexion->query("SHOW TABLES LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
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

    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
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

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'productsimg';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'No se pudo crear la carpeta de imagenes'];
    }

    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $random = uniqid('img_', true);
    }

    $fileName = 'prod_' . date('Ymd_His') . '_' . str_replace('.', '', (string)$random) . '.' . $extension;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['ok' => false, 'error' => 'No se pudo guardar la imagen en servidor'];
    }

    return ['ok' => true, 'path' => 'productsimg/' . $fileName];
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

function findProductsTable(mysqli $conexion)
{
    $candidates = ['producto', 'productos'];
    foreach ($candidates as $table) {
        if (tableExists($conexion, $table)) {
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

function findIdColumn(array $columnMap)
{
    $candidates = ['prod_id', 'pro_id', 'producto_id', 'id'];
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
    if ($estado === 'AGOTADO' || $estado === '0') {
        return 'AGOTADO';
    }
    return 'DISPONIBLE';
}

function normalizeEstadoTinyint($value)
{
    return normalizeEstadoTexto($value) === 'AGOTADO' ? 0 : 1;
}

function resolveTipId(mysqli $conexion, $categoria)
{
    if (!tableExists($conexion, 'tipo_producto')) {
        return null;
    }

    $categoria = trim((string)$categoria);
    if ($categoria !== '') {
        $stmt = $conexion->prepare('SELECT tip_id FROM tipo_producto WHERE UPPER(tip_nombre) = UPPER(?) LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $categoria);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row && isset($row['tip_id'])) {
                return (int)$row['tip_id'];
            }
        }

        $stmt = $conexion->prepare('INSERT INTO tipo_producto (tip_nombre, tip_descripcion) VALUES (?, ?)');
        if ($stmt) {
            $desc = 'Creado automaticamente desde panel admin';
            $stmt->bind_param('ss', $categoria, $desc);
            $ok = $stmt->execute();
            $newId = (int)$stmt->insert_id;
            $stmt->close();
            if ($ok && $newId > 0) {
                return $newId;
            }
        }
    }

    $result = $conexion->query('SELECT tip_id FROM tipo_producto ORDER BY tip_id ASC LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;
    return $row && isset($row['tip_id']) ? (int)$row['tip_id'] : null;
}

function resolveProvId(mysqli $conexion)
{
    if (!tableExists($conexion, 'proveedor')) {
        return null;
    }

    $result = $conexion->query('SELECT prov_id FROM proveedor WHERE prov_estatus = 1 ORDER BY prov_id ASC LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;
    if ($row && isset($row['prov_id'])) {
        return (int)$row['prov_id'];
    }

    $result = $conexion->query('SELECT prov_id FROM proveedor ORDER BY prov_id ASC LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;
    if ($row && isset($row['prov_id'])) {
        return (int)$row['prov_id'];
    }

    $stmt = $conexion->prepare('INSERT INTO proveedor (prov_nombre, prov_estatus) VALUES (?, 1)');
    if (!$stmt) {
        return null;
    }
    $name = 'Proveedor General';
    $stmt->bind_param('s', $name);
    $ok = $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $ok && $id > 0 ? $id : null;
}

function upsertImageProducto(mysqli $conexion, $productId, $imgPath)
{
    if ($productId <= 0 || trim((string)$imgPath) === '' || !tableExists($conexion, 'imagen_producto')) {
        return;
    }

    $stmt = $conexion->prepare('SELECT img_prod_id FROM imagen_producto WHERE prod_id = ? AND img_principal = 1 LIMIT 1');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row && isset($row['img_prod_id'])) {
        $imgId = (int)$row['img_prod_id'];
        $stmt = $conexion->prepare('UPDATE imagen_producto SET img_url = ? WHERE img_prod_id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $imgPath, $imgId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $stmt = $conexion->prepare('INSERT INTO imagen_producto (prod_id, img_url, img_principal) VALUES (?, ?, 1)');
    if ($stmt) {
        $stmt->bind_param('is', $productId, $imgPath);
        $stmt->execute();
        $stmt->close();
    }
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$table = findProductsTable($conexion);
if ($table === null) {
    jsonError('No se encontro tabla de productos (producto/productos)', 500);
    $conexion->close();
    exit;
}

$columns = describeTable($conexion, $table);
if (!$columns) {
    jsonError('No se pudo leer la estructura de la tabla de productos', 500);
    $conexion->close();
    exit;
}
$columnMap = [];
foreach ($columns as $col) {
    $columnMap[$col['Field']] = $col;
}
$idColumn = findIdColumn($columnMap);
if ($idColumn === null) {
    jsonError('No se encontro columna ID para productos', 500);
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
    $orderCol = quoteIdent($idColumn);
    $sql = 'SELECT * FROM ' . $quotedTable . ' ORDER BY ' . $orderCol . ' DESC LIMIT 200';
    $result = $conexion->query($sql);
    if (!$result) {
        jsonError('No se pudieron cargar productos', 500);
        $conexion->close();
        exit;
    }

    $imagesMap = [];
    if (tableExists($conexion, 'imagen_producto')) {
        $imgResult = $conexion->query('SELECT prod_id, img_url, img_principal FROM imagen_producto ORDER BY img_principal DESC, img_prod_id DESC');
        if ($imgResult) {
            while ($img = $imgResult->fetch_assoc()) {
                $pid = (int)($img['prod_id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                if (!isset($imagesMap[$pid])) {
                    $imagesMap[$pid] = (string)($img['img_url'] ?? '');
                }
            }
        }
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)pickValue($row, ['prod_id', 'pro_id', 'producto_id', 'id'], 0);
        $imgFromProduct = (string)pickValue($row, ['prod_imagen', 'pro_imagen', 'imagen', 'imagen_url', 'foto', 'url_imagen'], '');
        $imgPath = $imgFromProduct !== '' ? $imgFromProduct : ($imagesMap[$id] ?? '');

        $rows[] = [
            'id' => $id,
            'nombre' => (string)pickValue($row, ['prod_nombre', 'pro_nombre', 'nombre', 'producto_nombre', 'titulo'], ''),
            'descripcion' => (string)pickValue($row, ['prod_descripcion', 'pro_descripcion', 'descripcion', 'detalle'], ''),
            'categoria' => (string)pickValue($row, ['prod_categoria', 'pro_categoria', 'categoria', 'tipo'], ''),
            'precio' => (string)pickValue($row, ['prod_precio', 'pro_precio', 'precio', 'costo'], ''),
            'stock' => (string)pickValue($row, ['prod_stock', 'pro_stock', 'stock', 'existencia', 'inventario'], ''),
            'codigo' => (string)pickValue($row, ['prod_codigo', 'pro_codigo', 'codigo', 'sku'], ''),
            'estado' => normalizeEstadoTexto((string)pickValue($row, ['prod_estatus', 'pro_estado', 'estado', 'estatus', 'disponibilidad'], '1')),
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

if ($action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if ($id <= 0) {
        jsonError('ID de producto invalido', 422);
        $conexion->close();
        exit;
    }

    $quotedTable = quoteIdent($table);
    $quotedId = quoteIdent($idColumn);
    $pathsToDelete = [];

    $stmt = $conexion->prepare('SELECT * FROM ' . $quotedTable . ' WHERE ' . $quotedId . ' = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $mainImg = (string)pickValue($row, ['prod_imagen', 'pro_imagen', 'imagen', 'imagen_url', 'foto', 'url_imagen'], '');
            if ($mainImg !== '') {
                $pathsToDelete[] = $mainImg;
            }
        }
    }

    if (tableExists($conexion, 'imagen_producto')) {
        $stmt = $conexion->prepare('SELECT img_url FROM imagen_producto WHERE prod_id = ?');
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

    $stmt = $conexion->prepare('DELETE FROM ' . $quotedTable . ' WHERE ' . $quotedId . ' = ? LIMIT 1');
    if (!$stmt) {
        jsonError('No se pudo preparar borrado', 500);
        $conexion->close();
        exit;
    }
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    if ($ok && tableExists($conexion, 'imagen_producto')) {
        $stmt = $conexion->prepare('DELETE FROM imagen_producto WHERE prod_id = ?');
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
        jsonError('No se pudo borrar el producto', 500);
        exit;
    }
    if ($affectedRows < 1) {
        jsonError('Producto no encontrado para borrar', 404);
        exit;
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$nombre = trim((string)firstExistingValue($body, ['nombre', 'prod_nombre', 'pro_nombre', 'name']));
$descripcion = trim((string)firstExistingValue($body, ['descripcion', 'prod_descripcion', 'pro_descripcion', 'description']));
$categoria = strtoupper(trim((string)firstExistingValue($body, ['categoria', 'prod_categoria', 'pro_categoria', 'category'])));
$codigo = trim((string)firstExistingValue($body, ['codigo', 'prod_codigo', 'pro_codigo', 'sku']));
$estado = strtoupper(trim((string)firstExistingValue($body, ['estado', 'prod_estatus', 'pro_estado', 'estatus'])));
$imagen = trim((string)firstExistingValue($body, ['imagen', 'prod_imagen', 'pro_imagen', 'imagen_url', 'image']));
$precioRaw = firstExistingValue($body, ['precio', 'prod_precio', 'pro_precio', 'price']);
$stockRaw = firstExistingValue($body, ['stock', 'prod_stock', 'pro_stock', 'existencia']);

$precio = is_numeric($precioRaw) ? (float)$precioRaw : null;
$stock = is_numeric($stockRaw) ? (int)$stockRaw : null;

if ($nombre === '' || $descripcion === '' || $precio === null || $stock === null) {
    jsonError('Faltan campos obligatorios: nombre, descripcion, precio, stock', 422);
    $conexion->close();
    exit;
}
if ($precio <= 0 || $stock < 0) {
    jsonError('Precio debe ser mayor a 0 y stock no negativo', 422);
    $conexion->close();
    exit;
}
if ($estado === '') {
    $estado = 'DISPONIBLE';
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

$tipId = resolveTipId($conexion, $categoria);
$provId = resolveProvId($conexion);

if ((isset($columnMap['tip_id']) || isset($columnMap['tipo_id'])) && ($tipId === null || $tipId <= 0)) {
    jsonError('No se pudo resolver tip_id para el producto', 500);
    $conexion->close();
    exit;
}
if ((isset($columnMap['prov_id']) || isset($columnMap['proveedor_id'])) && ($provId === null || $provId <= 0)) {
    jsonError('No se pudo resolver prov_id para el producto', 500);
    $conexion->close();
    exit;
}

$logicalToCandidates = [
    'nombre' => ['prod_nombre', 'pro_nombre', 'nombre', 'producto_nombre', 'titulo'],
    'descripcion' => ['prod_descripcion', 'pro_descripcion', 'descripcion', 'detalle'],
    'categoria' => ['prod_categoria', 'pro_categoria', 'categoria', 'tipo'],
    'precio' => ['prod_precio', 'pro_precio', 'precio', 'costo'],
    'stock' => ['prod_stock', 'pro_stock', 'stock', 'existencia', 'inventario'],
    'codigo' => ['prod_codigo', 'pro_codigo', 'codigo', 'sku'],
    'estado' => ['prod_estatus', 'pro_estado', 'estado', 'estatus', 'disponibilidad'],
    'imagen' => ['prod_imagen', 'pro_imagen', 'imagen', 'imagen_url', 'foto', 'url_imagen'],
    'tip_id' => ['tip_id', 'tipo_id'],
    'prov_id' => ['prov_id', 'proveedor_id'],
];

$logicalValues = [
    'nombre' => $nombre,
    'descripcion' => $descripcion,
    'categoria' => $categoria,
    'precio' => $precio,
    'stock' => $stock,
    'codigo' => $codigo,
    'estado' => $estado,
    'imagen' => $imagen,
    'tip_id' => $tipId,
    'prov_id' => $provId,
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
    if ($logical === 'estado' && $foundColumn === 'prod_estatus') {
        $value = normalizeEstadoTinyint($value);
    }
    if ($value === '' || $value === null) {
        continue;
    }
    $insertColumns[] = $foundColumn;
    $insertValues[] = $value;
}

if (count($insertColumns) === 0) {
    jsonError('No se pudieron mapear columnas para guardar producto', 500);
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
$quotedId = quoteIdent($idColumn);
if ($quotedTable === null || $quotedId === null) {
    jsonError('Error interno de tabla/ID', 500);
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
        jsonError('ID de producto invalido', 422);
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

    $typesUpd = $types . 'i';
    $valuesUpd = $bindValues;
    $valuesUpd[] = $id;
    $stmt->bind_param($typesUpd, ...$valuesUpd);
    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        $conexion->close();
        jsonError('No se pudo actualizar producto: ' . $error, 500);
        exit;
    }

    upsertImageProducto($conexion, $id, $imagen);
    $conexion->close();
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
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
$newId = (int)$stmt->insert_id;
$error = $stmt->error;
$stmt->close();

if (!$ok) {
    $conexion->close();
    jsonError('No se pudo guardar producto: ' . $error, 500);
    exit;
}

upsertImageProducto($conexion, $newId, $imagen);
$conexion->close();

echo json_encode(['ok' => true, 'id' => $newId], JSON_UNESCAPED_UNICODE);

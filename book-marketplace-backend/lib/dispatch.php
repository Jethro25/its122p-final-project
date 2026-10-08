<?php
/**
 * Routes a single incoming HTTP request to the right Crud method and
 * writes the JSON response. Every file in /api calls this once it has
 * built its Crud instance.
 *
 *   GET    /api/user.php            -> list (supports filters, limit, offset)
 *   GET    /api/user.php?id=5       -> show one row
 *   POST   /api/user.php            -> create (JSON body)
 *   PUT    /api/user.php?id=5       -> update (JSON body, partial)
 *   DELETE /api/user.php?id=5       -> delete
 */
function dispatch_crud_request(Crud $crud, string $primaryKeyName): void
{
    global $pdo; /* bring the $pdo connection into function scope */

    /* CORS and no-cache headers are already sent by bootstrap.php */

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    require_authenticated_user($pdo);

    $id = $_GET['id'] ?? null;

    try {
        switch ($method) {
            case 'GET':
                if ($id !== null) {
                    $row = $crud->show($id);
                    if (!$row) {
                        Response::error("Record with {$primaryKeyName} = {$id} not found.", 404);
                    }
                    Response::json($row);
                } else {
                    Response::json($crud->index($_GET));
                }
                break;

            case 'POST':
                $body = read_json_body();
                $created = $crud->create($body);
                Response::json($created, 201);
                break;

            case 'PUT':
            case 'PATCH':
                if ($id === null) {
                    Response::error("Query parameter '{$primaryKeyName}' (as ?id=) is required for updates.", 400);
                }
                $body = read_json_body();
                $updated = $crud->update($id, $body);
                if ($updated === null) {
                    Response::error("Record with {$primaryKeyName} = {$id} not found.", 404);
                }
                Response::json($updated);
                break;

            case 'DELETE':
                if ($id === null) {
                    Response::error("Query parameter '{$primaryKeyName}' (as ?id=) is required for deletes.", 400);
                }
                $ok = $crud->delete($id);
                if (!$ok) {
                    Response::error("Record with {$primaryKeyName} = {$id} not found.", 404);
                }
                Response::json(['message' => 'Deleted', $primaryKeyName => $id]);
                break;

            default:
                Response::error('Method not allowed.', 405);
        }
    } catch (InvalidArgumentException $e) {
        Response::error($e->getMessage(), 422);
    } catch (PDOException $e) {
        database_error_response($e);
    }
}

function read_json_body(): array
{
    // An endpoint may replace fields the server must decide itself (e.g. who created it)
    return $GLOBALS['librowse_body_override'] ?? request_body();
}

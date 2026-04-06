<?php
/**
 * AJAX router for Network Analyze plugin
 *
 * All requests are POST with 'cmd' parameter.
 * Returns JSON response.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/NetworkHelper.php';

$cmd = isset($_POST['cmd']) ? $_POST['cmd'] : '';

$helper = new NetworkHelper();

switch ($cmd) {
    case 'get_process_list':
        echo json_encode($helper->getProcessList());
        break;

    case 'get_connections':
        echo json_encode($helper->getConnections());
        break;

    case 'get_interfaces':
        echo json_encode($helper->getInterfaces());
        break;

    case 'get_namespaces':
        echo json_encode($helper->getNamespaces());
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown command: ' . $cmd]);
        break;
}

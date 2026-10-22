<?php
declare(strict_types=1);

namespace App\Billing;

use App\Shared\Database;
use App\Shared\Response;
use PDO;

class BillingController
{
    /**
     * Listado de Documentos Tributarios Electrónicos (DTE)
     */
    public function listDte(): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            SELECT v.id, v.numero_venta, v.tipo_documento, v.numero_control_mh,
                   v.codigo_generacion_mh, v.sello_recepcion_mh, v.estado_dte,
                   v.total, v.fecha_hora, c.nombre as cliente_nombre
            FROM ventas v
            JOIN clientes c ON c.id = v.cliente_id
            WHERE v.estado = 'COMPLETADA'
            ORDER BY v.fecha_hora DESC
            LIMIT 50
        ");
        $dtes = $stmt->fetchAll();

        $empresa = $pdo->query("SELECT * FROM configuracion_empresa LIMIT 1")->fetch();

        Response::json([
            'dtes' => $dtes,
            'empresa' => $empresa
        ]);
    }

    /**
     * Generar estructura JSON oficial del DTE según el Ministerio de Hacienda de El Salvador
     */
    public function getJsonDte(): void
    {
        $ventaId = (int)($_GET['venta_id'] ?? 0);
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT v.*, c.nombre as cliente_nombre, c.nit as cliente_nit,
                   c.nrc as cliente_nrc, c.dui as cliente_dui, c.direccion as cliente_dir,
                   c.telefono as cliente_tel, c.correo as cliente_correo
            FROM ventas v
            JOIN clientes c ON c.id = v.cliente_id
            WHERE v.id = :id
        ");
        $stmt->execute(['id' => $ventaId]);
        $venta = $stmt->fetch();

        if (!$venta) {
            Response::error('Venta no encontrada');
        }

        $stmtItems = $pdo->prepare("
            SELECT vi.*, p.nombre, p.codigo_interno, p.unidad_medida
            FROM venta_items vi
            JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = :id
        ");
        $stmtItems->execute(['id' => $ventaId]);
        $items = $stmtItems->fetchAll();

        $empresa = $pdo->query("SELECT * FROM configuracion_empresa LIMIT 1")->fetch();

        // Estructura oficial DTE Ministerio de Hacienda El Salvador
        $cuerpoDocumento = [];
        $numItem = 1;
        foreach ($items as $it) {
            $cuerpoDocumento[] = [
                'numItem' => $numItem++,
                'tipoItem' => 1, // 1: Bienes, 2: Servicios
                'numeroDocumento' => null,
                'cantidad' => (float)$it['cantidad'],
                'codigo' => $it['codigo_interno'],
                'codTributo' => null,
                'uniMedida' => 59, // 59: Unidad
                'descripcion' => $it['nombre'],
                'precioUni' => (float)$it['precio_unitario'],
                'montoDescu' => (float)$it['descuento'],
                'ventaNoSuj' => 0.0,
                'ventaExenta' => 0.0,
                'ventaGravada' => (float)$it['total_linea'],
                'tributos' => ['20'], // 20: IVA 13%
                'psv' => 0.0,
                'noGravado' => 0.0
            ];
        }

        $dteJson = [
            'identificacion' => [
                'version' => 1,
                'ambiente' => $empresa['ambiente_dte'] ?? '00', // 00: Pruebas, 01: Prod
                'tipoDte' => $venta['tipo_documento'],
                'numeroControl' => $venta['numero_control_mh'],
                'codigoGeneracion' => $venta['codigo_generacion_mh'],
                'tipoModelo' => 1, // Previo
                'tipoOperacion' => 1, // Normal
                'tipoContingencia' => null,
                'motivoContin' => null,
                'fecEmi' => date('Y-m-d', strtotime($venta['fecha_hora'])),
                'horEmi' => date('H:i:s', strtotime($venta['fecha_hora'])),
                'tipoMoneda' => 'USD'
            ],
            'emisor' => [
                'nit' => str_replace('-', '', $empresa['nit']),
                'nrc' => str_replace('-', '', $empresa['nrc']),
                'nombre' => $empresa['nombre_razon_social'],
                'codActividad' => $empresa['codigo_actividad'],
                'descActividad' => $empresa['actividad_economica'],
                'nombreComercial' => $empresa['nombre_comercial'],
                'tipoEstablecimiento' => '02',
                'direccion' => [
                    'departamento' => '06',
                    'municipio' => '14',
                    'complemento' => $empresa['direccion']
                ],
                'telefono' => $empresa['telefono'],
                'correo' => $empresa['correo']
            ],
            'receptor' => [
                'tipoDocumento' => !empty($venta['cliente_nit']) ? '36' : (!empty($venta['cliente_dui']) ? '13' : '37'),
                'numDocumento' => $venta['cliente_nit'] ?? ($venta['cliente_dui'] ?? '00000000-0'),
                'nrc' => $venta['cliente_nrc'] ?? null,
                'nombre' => $venta['cliente_nombre'],
                'codActividad' => null,
                'descActividad' => null,
                'direccion' => [
                    'departamento' => '06',
                    'municipio' => '14',
                    'complemento' => $venta['cliente_dir'] ?? 'San Salvador'
                ],
                'telefono' => $venta['cliente_tel'] ?? null,
                'correo' => $venta['cliente_correo'] ?? null
            ],
            'cuerpoDocumento' => $cuerpoDocumento,
            'resumen' => [
                'totalNoSuj' => 0.0,
                'totalExenta' => 0.0,
                'totalGravada' => (float)$venta['subtotal_gravado'],
                'subTotalVentas' => (float)$venta['subtotal_gravado'],
                'descuNoSuj' => 0.0,
                'descuExenta' => 0.0,
                'descuGravada' => (float)$venta['descuento'],
                'porcentajeDescuento' => 0.0,
                'totalDescu' => (float)$venta['descuento'],
                'tributos' => [
                    [
                        'codigo' => '20',
                        'descripcion' => 'Impuesto al Valor Agregado 13%',
                        'valor' => (float)$venta['iva']
                    ]
                ],
                'subTotal' => (float)$venta['subtotal_gravado'],
                'ivaRete1' => 0.0,
                'reteRenta' => 0.0,
                'montoTotalOperacion' => (float)$venta['total'],
                'totalNoGravado' => 0.0,
                'totalPagar' => (float)$venta['total'],
                'totalLetras' => 'DÓLARES ESTADOUNIDENSES',
                'condicionOperacion' => 1 // 1: Contado, 2: Crédito
            ]
        ];

        Response::json([
            'venta_id' => $ventaId,
            'codigo_generacion' => $venta['codigo_generacion_mh'],
            'numero_control' => $venta['numero_control_mh'],
            'dte_json' => $dteJson
        ]);
    }
}

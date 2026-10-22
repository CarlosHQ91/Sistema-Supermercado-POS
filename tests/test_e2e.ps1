$ErrorActionPreference = 'Stop'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

# 1. Login
$loginBody = @{ username = 'admin'; password = 'admin123' } | ConvertTo-Json
$resLogin = Invoke-RestMethod -Uri 'http://localhost:8000/api/auth/login' -Method Post -Body $loginBody -ContentType 'application/json' -WebSession $session
Write-Host "1. Login exitoso:" $resLogin.ok "- Usuario:" $resLogin.user.nombre_completo

# 2. Caja
$resCash = Invoke-RestMethod -Uri 'http://localhost:8000/api/cash/session' -Method Get -WebSession $session
Write-Host "2. Estado Caja:" $resCash.session.estado "- Monto Apertura: $" $resCash.session.monto_apertura

# 3. Venta Checkout
$checkoutBody = @{
    cliente_id = 1
    tipo_documento = '01'
    items = @(
        @{ producto_id = 1; cantidad = 2; descuento = 0.0 },
        @{ producto_id = 2; cantidad = 1; descuento = 0.0 }
    )
    pagos = @(
        @{ metodo_pago = 'EFECTIVO'; monto = 5.00; monto_recibido = 10.00 }
    )
} | ConvertTo-Json -Depth 5

try {
    $resVenta = Invoke-RestMethod -Uri 'http://localhost:8000/api/pos/checkout' -Method Post -Body $checkoutBody -ContentType 'application/json' -WebSession $session
    $vId = $resVenta.venta.venta_id
    Write-Host "3. Venta procesada:" $resVenta.ok "- Factura:" $resVenta.venta.numero_venta "- Total: $" $resVenta.venta.total "- DTE UUID:" $resVenta.venta.codigo_generacion
} catch {
    $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
    $errText = $sr.ReadToEnd()
    Write-Host "ERROR EN CHECKOUT:" $errText
    exit 1
}

# 4. Ticket Reimpresión
$resTicket = Invoke-RestMethod -Uri "http://localhost:8000/api/pos/ticket?id=$vId" -Method Get -WebSession $session
Write-Host "4. Ticket recuperado con" $resTicket.items.Count "artículos - Cajero:" $resTicket.venta.cajero_nombre

# 5. Anulación con PIN de supervisor
$voidBody = @{
    venta_id = $vId
    motivo = 'Error en cobro de prueba automatizada'
    pin_supervisor = '1234'
} | ConvertTo-Json
$resVoid = Invoke-RestMethod -Uri 'http://localhost:8000/api/sales/void' -Method Post -Body $voidBody -ContentType 'application/json' -WebSession $session
Write-Host "5. Anulación con Supervisor PIN:" $resVoid.ok "- Msg:" $resVoid.message

# 6. Crear Usuario Supervisor
$uSuffix = Get-Random -Minimum 100 -Maximum 999
$userBody = @{
    username = "supervisor_$uSuffix"
    nombre_completo = 'Carlos Supervisor'
    rol = 'SUPERVISOR'
    pin = '7777'
    password = 'pass_supervisor_123'
    activo = $true
} | ConvertTo-Json
$resUser = Invoke-RestMethod -Uri 'http://localhost:8000/api/users' -Method Post -Body $userBody -ContentType 'application/json' -WebSession $session
Write-Host "6. Creación de usuario:" $resUser.ok "- User ID:" $resUser.user_id

# 7. Categoría y Proveedor
$randSuffix = Get-Random -Minimum 100 -Maximum 999
$catBody = @{ nombre = "Bebidas y Refrescos $randSuffix"; descripcion = 'Licores y jugos'; activo = $true } | ConvertTo-Json
try {
    $resCat = Invoke-RestMethod -Uri 'http://localhost:8000/api/catalog/categories' -Method Post -Body $catBody -ContentType 'application/json' -WebSession $session
    Write-Host "7. Creación de Categoría:" $resCat.ok "- ID:" $resCat.id
} catch {
    $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
    Write-Host "ERROR CATEGORIA:" $sr.ReadToEnd()
    exit 1
}

$provBody = @{
    nombre_comercial = "Distribuidora Salvadoreña S.A. $randSuffix"
    razon_social = "Distribuidora Salvadoreña S.A. de C.V. $randSuffix"
    nit = '0614-010190-101-1'
    nrc = '123456-7'
    contacto_nombre = 'Juan Perez'
    telefono = '2255-0000'
} | ConvertTo-Json
try {
    $resProv = Invoke-RestMethod -Uri 'http://localhost:8000/api/catalog/suppliers' -Method Post -Body $provBody -ContentType 'application/json' -WebSession $session
    Write-Host "8. Creación de Proveedor:" $resProv.ok "- Proveedor ID:" $resProv.id
} catch {
    $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
    Write-Host "ERROR PROVEEDOR:" $sr.ReadToEnd()
    exit 1
}

# 9. Compra a Proveedor
$compraBody = @{
    proveedor_id = $resProv.id
    tipo_documento = 'CCF'
    numero_factura = "CCF-99$randSuffix"
    fecha = (Get-Date -Format 'yyyy-MM-dd')
    condicion_pago = 'CONTADO'
    items = @(
        @{ producto_id = 1; cantidad = 50; costo_unitario = 0.85 }
    )
} | ConvertTo-Json -Depth 5
try {
    $resCompra = Invoke-RestMethod -Uri 'http://localhost:8000/api/purchases' -Method Post -Body $compraBody -ContentType 'application/json' -WebSession $session
    Write-Host "9. Registro Compra Factura Proveedor:" $resCompra.ok "- Compra Num:" $resCompra.compra.numero_compra "- Total: $" $resCompra.compra.total
} catch {
    $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
    Write-Host "ERROR COMPRA:" $sr.ReadToEnd()
    exit 1
}

# 10. Historial de Ventas
$resHistorial = Invoke-RestMethod -Uri 'http://localhost:8000/api/sales/history?fecha_desde=2026-10-01&fecha_hasta=2026-10-17' -Method Get -WebSession $session
Write-Host "10. Historial total ventas auditadas:" $resHistorial.sales.Count


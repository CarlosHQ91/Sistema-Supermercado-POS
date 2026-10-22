$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

# 1. Login
$loginBody = @{ username = 'admin'; password = 'Password123!' } | ConvertTo-Json
$loginRes = Invoke-RestMethod -Uri 'http://localhost:8000/api/auth/login' -Method POST -Body $loginBody -ContentType 'application/json' -WebSession $session
Write-Host "1. Login:" $loginRes.ok $loginRes.user.username

# 2. Guardar / Modificar producto
$prodBody = @{
    id = 0
    codigo_interno = 'TEST-FEFO-01'
    codigo_barra = '741999999001'
    nombre = 'YOGURT FRESA 1L PROBIOTICO'
    categoria_id = 1
    presentacion = 'Botella 1L'
    precio_unitario = 2.85
    costo_unitario = 1.95
    stock_minimo = 15
    es_pesable = $false
    maneja_lotes = $true
} | ConvertTo-Json
$prodRes = Invoke-RestMethod -Uri 'http://localhost:8000/api/inventory/save-product' -Method POST -Body $prodBody -ContentType 'application/json' -WebSession $session
Write-Host "2. Save Product:" $prodRes.ok $prodRes.message

# 3. Importación Masiva CSV
$csvData = @"
codigo,nombre,categoria,precio_unitario,costo_unitario,codigo_barra,stock_inicial,lote,vencimiento
IMP-001,Galleta Chocolate 120g,Snacks,0.75,0.45,741888001,100,L-202610A,2027-04-15
IMP-002,Jugo Naranja 1L,Bebidas,1.60,1.10,741888002,50,L-202610B,2026-11-01
IMP-003,Queso Crema 200g,Lacteos,1.90,1.30,741888003,30,L-202610C,2026-10-25
"@
$csvBody = @{ csv_content = $csvData; bodega_id = 1 } | ConvertTo-Json
$csvRes = Invoke-RestMethod -Uri 'http://localhost:8000/api/inventory/import-csv' -Method POST -Body $csvBody -ContentType 'application/json' -WebSession $session
Write-Host "3. Import CSV:" $csvRes.ok ("Importados: " + $csvRes.imported_count)

# 4. Ajuste de Stock con PIN
$adjBody = @{
    producto_id = 1
    bodega_id = 1
    tipo_ajuste = 'MERMA'
    cantidad = 2.0
    motivo = 'Prueba automatizada de merma por empaque roto'
    supervisor_pin = '1234'
} | ConvertTo-Json
$adjRes = Invoke-RestMethod -Uri 'http://localhost:8000/api/inventory/adjust' -Method POST -Body $adjBody -ContentType 'application/json' -WebSession $session
Write-Host "4. Stock Adjust:" $adjRes.ok $adjRes.message

# 5. Listar Lotes FEFO
$lotsRes = Invoke-RestMethod -Uri 'http://localhost:8000/api/inventory/lots' -Method GET -WebSession $session
Write-Host "5. Lots Count:" $lotsRes.lots.Count "First lot semaforo:" $lotsRes.lots[0].estado_semaforo

# 6. Reportes Especializados
$valRes = Invoke-RestMethod -Uri 'http://localhost:8000/api/reports/kardex-valuation' -Method GET -WebSession $session
Write-Host "6. Kardex Valuation Total: $" $valRes.valor_total_inventario "Items:" $valRes.total_items

$repSales = Invoke-RestMethod -Uri 'http://localhost:8000/api/reports/sales-by-product' -Method GET -WebSession $session
Write-Host "7. Sales by Product Items:" $repSales.items.Count

$shiftsRes = Invoke-RestMethod -Uri 'http://localhost:8000/api/reports/cash-shifts' -Method GET -WebSession $session
Write-Host "8. Cash Shifts Count:" $shiftsRes.shifts.Count

# 7. Contabilidad: Libros y Balance
$balRes = Invoke-RestMethod -Uri 'http://localhost:8000/api/accounting/balance-comprobacion' -Method GET -WebSession $session
Write-Host "9. Balance Comprobacion Cuentas:" $balRes.cuentas.Count "Debe:" $balRes.total_debe "Haber:" $balRes.total_haber "Cuadrado:" $balRes.cuadrado

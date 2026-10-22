$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginBody = @{ username = 'admin'; password = 'admin123' } | ConvertTo-Json
$resLogin = Invoke-RestMethod -Uri 'http://localhost:8000/api/auth/login' -Method Post -Body $loginBody -ContentType 'application/json' -WebSession $session

$resCash = Invoke-RestMethod -Uri 'http://localhost:8000/api/cash/session' -Method Get -WebSession $session
Write-Host "Caja activa:" $resCash.has_active_session

if (-not $resCash.has_active_session) {
    Write-Host "Abriendo caja..."
    $openBody = @{ caja_id = 1; denominaciones = @(@{ valor = 50.0; tipo = 'BILLETE'; cantidad = 1 }) } | ConvertTo-Json -Depth 3
    $resOpen = Invoke-RestMethod -Uri 'http://localhost:8000/api/cash/open' -Method Post -Body $openBody -ContentType 'application/json' -WebSession $session
    $sesId = $resOpen.session.id
} else {
    $sesId = $resCash.session.id
}

Write-Host "Cerrando sesion ID: $sesId"
$closeBody = @{
    sesion_id = $sesId
    pin_supervisor = '1234'
    denominaciones = @(
        @{ valor = 50.0; tipo = 'BILLETE'; cantidad = 1 }
    )
    notas = 'Prueba cierre'
} | ConvertTo-Json -Depth 3

try {
    $resClose = Invoke-RestMethod -Uri 'http://localhost:8000/api/cash/close' -Method Post -Body $closeBody -ContentType 'application/json' -WebSession $session
    Write-Host "RES CLOSE JSON:" ($resClose | ConvertTo-Json -Depth 5)
} catch {
    $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
    Write-Host "ERROR EN CIERRE:" $sr.ReadToEnd()
}

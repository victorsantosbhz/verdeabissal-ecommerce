# Verde Abissal - Recria container WordPress + valida plugins
# Sem afetar o banco de dados.

$ErrorActionPreference = "Continue"
$proj = $PSScriptRoot

function Section($t) {
    Write-Output ""
    Write-Output "===== $t ====="
}

Section "0) Onde estamos?"
Write-Output "Pasta do projeto: $proj"

Section "1) PARANDO e REMOVENDO somente o container wordpress (DB preservado)"
docker compose stop wordpress 2>&1
docker compose rm -f wordpress 2>&1

Section "2) Subindo o WordPress de novo (forca recreate)"
docker compose up -d --build --force-recreate wordpress 2>&1

Section "3) Aguardando 5s para o container iniciar..."
Start-Sleep -Seconds 5

Section "4) Status dos containers"
docker compose ps 2>&1

Section "5) Mounts do container WordPress (procurando wordpress_data)"
docker inspect verdeabissal_app --format '{{range .Mounts}}{{.Type}} | {{.Source}} -> {{.Destination}} | RW:{{.RW}}`n{{end}}' 2>&1

Section "6) Listagem REAL de /var/www/html/wp-content/plugins/ DENTRO do container"
docker exec verdeabissal_app ls -la /var/www/html/wp-content/plugins/ 2>&1

Section "7) Subdiretorio do verdeabissal-calc-aquario (deve mostrar 4 itens)"
docker exec verdeabissal_app ls -la /var/www/html/wp-content/plugins/verdeabissal-calc-aquario/ 2>&1

Section "8) Subdiretorio do va-product-order"
docker exec verdeabissal_app ls -la /var/www/html/wp-content/plugins/va-product-order/ 2>&1

Section "9) PHP -l (sintaxe dos plugins)"
docker exec verdeabissal_app php -l /var/www/html/wp-content/plugins/verdeabissal-calc-aquario/verdeabissal-calc-aquario.php 2>&1
docker exec verdeabissal_app php -l /var/www/html/wp-content/plugins/va-product-order/va-product-order.php 2>&1

Section "10) Cabecalho do plugin Calculadora (pegando do container)"
docker exec verdeabissal_app head -20 /var/www/html/wp-content/plugins/verdeabissal-calc-aquario/verdeabissal-calc-aquario.php 2>&1

Section "FIM"
Write-Output "Se na secao 6 voce ja ve 'verdeabissal-calc-aquario' e 'va-product-order' como pastas, esta resolvido."
Write-Output "Acesse http://localhost/wp-admin/plugins.php e veja a aba 'Todos'."

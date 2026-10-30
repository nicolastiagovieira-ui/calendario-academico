$ErrorActionPreference='Stop'
Get-ChildItem src,public,bin,config -Recurse -Filter *.php | ForEach-Object {
  php -l $_.FullName
  if($LASTEXITCODE -ne 0){throw "Sintaxe inválida: $($_.Name)"}
}
node --test demo/mock-api.test.mjs
if($LASTEXITCODE -ne 0){throw 'Falha nos testes da demonstração'}

# Genera dist\RiversoPrintHub.exe: un solo archivo, sin necesidad de instalar .NET en el PC.
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
dotnet publish "$here\RiversoPrintHub\RiversoPrintHub.csproj" -c Release -r win-x64 --self-contained true `
    -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:EnableCompressionInSingleFile=true `
    -p:DebugType=none -o "$here\dist"
Write-Host "Listo: $here\dist\RiversoPrintHub.exe"

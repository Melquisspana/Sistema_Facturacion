<?php

namespace App\Services\Contabilidad;

use App\Models\GmailCuenta;
use App\Services\Ppq\GmailClient;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Google\Service\Exception as GoogleServiceException;

/**
 * Sube el ZIP del paquete a Drive (carpeta Paquetes contabilidad/AAAA/MM) con la misma
 * cuenta de Google de Gmail y lo comparte solo con el correo de contabilidad.
 *
 * Subida reanudable por trozos de 8 MB: el ZIP nunca se carga entero en memoria. En un
 * reintento actualiza el archivo del mismo nombre en vez de duplicarlo. Scope
 * `drive.file`: el sistema solo ve los archivos y carpetas que él mismo creó.
 */
class SubidaDrivePaquete implements SubidaDrivePaqueteContrato
{
    public function __construct(private GmailClient $gmail) {}

    public function subir(string $ruta, string $nombre, int $anio, int $mes, string $correo): array
    {
        if (! GmailCuenta::actual()?->tienePermisoDrive()) {
            throw new PermisoDriveFaltante;
        }

        $client = $this->gmail->clienteAutenticado();
        $drive = new Drive($client);
        $carpeta = $this->carpeta($drive, 'Paquetes contabilidad', 'root');
        $carpeta = $this->carpeta($drive, (string) $anio, $carpeta);
        $carpeta = $this->carpeta($drive, sprintf('%02d', $mes), $carpeta);
        $existente = $this->buscar($drive, $nombre, $carpeta, 'application/zip');
        $archivo = fopen($ruta, 'rb');
        if ($archivo === false) {
            throw new \RuntimeException('No se pudo abrir el ZIP para subirlo a Drive.');
        }

        $defer = $client->shouldDefer();
        try {
            $client->setDefer(true);
            $metadata = new DriveFile(['name' => $nombre]);
            $opciones = ['uploadType' => 'resumable', 'fields' => 'id,webViewLink'];
            if ($existente) {
                $request = $drive->files->update($existente->getId(), $metadata, $opciones);
            } else {
                $metadata->setParents([$carpeta]);
                $request = $drive->files->create($metadata, $opciones);
            }
            $trozo = 8 * 1024 * 1024;
            $media = new MediaFileUpload($client, $request, 'application/zip', null, true, $trozo);
            $media->setFileSize(filesize($ruta));
            $resultado = false;
            while (! feof($archivo) && ! $resultado) {
                $bytes = fread($archivo, $trozo);
                if ($bytes === false) {
                    throw new \RuntimeException('No se pudo leer el ZIP para subirlo a Drive.');
                }
                if ($bytes !== '') {
                    $resultado = $media->nextChunk($bytes);
                }
            }
            if (! $resultado || empty($resultado->id)) {
                throw new \RuntimeException('Drive no confirmó la subida del ZIP.');
            }
        } finally {
            fclose($archivo);
            $client->setDefer($defer);
        }

        $id = (string) $resultado->id;
        // En los reintentos elimina permisos anteriores del archivo (excepto el dueño).
        // Nunca crea permisos públicos ni comparte la carpeta.
        $permisos = $drive->permissions->listPermissions($id, ['fields' => 'permissions(id,type,role,emailAddress),nextPageToken']);
        $lector = false;
        do {
            foreach ($permisos->getPermissions() as $permiso) {
                if ($permiso->getRole() === 'owner') {
                    continue;
                }
                if ($permiso->getType() === 'user' && strcasecmp((string) $permiso->getEmailAddress(), $correo) === 0 && $permiso->getRole() === 'reader') {
                    $lector = true;
                } else {
                    $drive->permissions->delete($id, $permiso->getId());
                }
            }
            $pagina = $permisos->getNextPageToken();
            if ($pagina) {
                $permisos = $drive->permissions->listPermissions($id, ['fields' => 'permissions(id,type,role,emailAddress),nextPageToken', 'pageToken' => $pagina]);
            }
        } while ($pagina);
        if (! $lector) {
            $permiso = new Permission(['type' => 'user', 'role' => 'reader', 'emailAddress' => $correo]);
            try {
                // Sin aviso de Google: el enlace ya va en nuestro correo.
                $drive->permissions->create($id, $permiso, ['sendNotificationEmail' => false]);
            } catch (GoogleServiceException $e) {
                // Drive exige el aviso cuando el correo no tiene cuenta de Google.
                if ($e->getCode() !== 400) {
                    throw $e;
                }
                $drive->permissions->create($id, $permiso, ['sendNotificationEmail' => true]);
            }
        }
        $archivoDrive = $drive->files->get($id, ['fields' => 'id,webViewLink']);
        if (! $archivoDrive->getWebViewLink()) {
            throw new \RuntimeException('Drive no devolvió el enlace del ZIP.');
        }

        return ['id' => $id, 'webViewLink' => $archivoDrive->getWebViewLink()];
    }

    private function carpeta(Drive $drive, string $nombre, string $padre): string
    {
        $existente = $this->buscar($drive, $nombre, $padre, 'application/vnd.google-apps.folder');
        if ($existente) {
            return $existente->getId();
        }

        return $drive->files->create(new DriveFile(['name' => $nombre, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$padre]]), ['fields' => 'id'])->getId();
    }

    private function buscar(Drive $drive, string $nombre, string $padre, string $tipo): ?DriveFile
    {
        $nombre = str_replace(['\\', "'"], ['\\\\', "\\'"], $nombre);
        $archivos = $drive->files->listFiles(['q' => "trashed = false and name = '{$nombre}' and '{$padre}' in parents and mimeType = '{$tipo}'", 'fields' => 'files(id)', 'pageSize' => 1]);

        return $archivos->getFiles()[0] ?? null;
    }
}

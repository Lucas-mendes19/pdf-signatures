<?php
 
namespace Lukelt\PdfSignatures;

use Exception;
use Lukelt\PdfSignatures\helper\OpenSSL;
use Lukelt\PdfSignatures\helper\Temp;

/**
 * Used for mapping PDF data by searching and returning their positions in the digital signature,
 * where it converts the information to a digital certificate and its information
 */
class PdfSignatures
{
    use Temp, OpenSSL;

    private static string $file;
    private static string $content;
    private static string $format;
    private static array $displacements;

    /**
     * Returns digital signatures with your information
     *
     * @param string $file content file or path file
     * @return array
     */
    public static function find(string $file, string $format = 'Y-m-d H:i:s'): array
    {
        $document = new Document($file);

        self::$file = $document->file;
        self::$content = $document->content;

        return self::displacementFind()::signatures($format);
    }

    /**
     * Search positions of digital signatures
     * @return mixed
     */
    public static function displacementFind(): mixed
    {
        $result = [];
        $regexp = '#ByteRange\[\s*(\d+) (\d+) (\d+)#';
        
        preg_match_all($regexp, self::$content, $result);  
        unset($result[0], $result[1]);
        $point = array_filter($result);

        if (empty($point))
            throw new Exception("Does not have digital signatures");

        for ($index = 0; $index < count($result[2]); $index++) { 
            self::$displacements[] = [
                'start' => $point[2][$index],
                'end' => $point[3][$index]
            ];
        }

        return self::class;
    }

    /**
     * Fetch the digital signature information by converting and processing
     * the certificate and returning its information
     * @return array
     */
    public static function signatures($format): array
    {
        $signaturesContent = [];

        foreach (self::$displacements as $displacement) {
            if ($stream = fopen(self::$file, 'rb')) {
                $signature = stream_get_contents(
                    $stream,
                    $displacement['end'] - $displacement['start'] - 2,
                    $displacement['start'] + 1
                );
                
                fclose($stream);
                
                $pathCertificate = self::path('pfx_', 'pfx');
                file_put_contents($pathCertificate, hex2bin($signature));
            }
        
            $pathText = self::path('der_', 'txt');
            self::exec($pathCertificate, $pathText);
            unlink($pathCertificate);

            $data = self::processCertificate($pathText);
            unlink($pathText);

            $plainTextContent = openssl_x509_parse($data);
            $signaturesContent[] = new Certificate($plainTextContent, $format);
        }

        return $signaturesContent;
    }

    /**
     * Process the digital certificate information
     * @return string
     */
    private static function processCertificate(string $path): string
    {
        $data = preg_split("/\\n\\r/", file_get_contents($path));
        $info = array_filter($data, fn($cert) => strlen($cert) > 2);
        return end($info);
    }
}
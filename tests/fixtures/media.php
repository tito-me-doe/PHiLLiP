<?php
function fixturePdf($text, $png = null, $pages = 1)
{
    $stream = "BT /F1 24 Tf 50 740 Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ") Tj ET";
    $image = '';
    if ($png !== null) {
        $width = unpack('N', substr($png, 16, 4))[1]; $height = unpack('N', substr($png, 20, 4))[1]; $data = '';
        if (ord($png[25]) !== 2) throw new RuntimeException('Fixture requires an RGB PNG');
        for ($offset = 8; $offset < strlen($png);) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            if (substr($png, $offset + 4, 4) === 'IDAT') $data .= substr($png, $offset + 8, $length);
            $offset += $length + 12;
        }
        $image = '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns ' . $width . ' >> /Length ' . strlen($data) . ">>\nstream\n" . $data . "\nendstream";
        $stream = 'q 612 0 0 792 0 0 cm /Im0 Do Q';
    }
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >>' . ($image ? ' /XObject << /Im0 6 0 R >>' : '') . ' >> /Contents 4 0 R >>',
        '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    if ($image) $objects[] = $image;
    $pageIds = [3];
    for ($page = 1; $page < $pages; $page++) { $objects[] = $objects[2]; $pageIds[] = count($objects); }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(function ($id) { return $id . ' 0 R'; }, $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $index => $object) { $offsets[] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 " . count($offsets) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
    return $pdf . 'trailer << /Size ' . count($offsets) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}

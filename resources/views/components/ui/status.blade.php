{{-- Badge untuk nilai status Khanza: reg_periksa.stts, status_bayar, kamar.status, kamar_inap.stts_pulang. --}}
@props(['value'])

@php
    $color = match ($value) {
        'Sudah', 'Sudah Bayar', 'KOSONG', 'Sehat', 'Sembuh', 'Membaik', 'Berkas Diterima' => 'green',
        'Belum', 'Belum Bayar', 'DIBOOKING', 'DIBERSIHKAN' => 'yellow',
        'Batal', 'Meninggal', 'Pulang Paksa', '+', 'PERBAIKAN' => 'red',
        'Dirawat', 'ISI', 'Ranap', '-' => 'blue',
        'Dirujuk', 'Rujuk', 'Pindah Kamar', 'APS' => 'orange',
        default => 'gray',
    };
    $label = $value === '-' ? 'Dirawat' : $value;
@endphp

<x-ui.badge :color="$color" {{ $attributes }}>{{ $label }}</x-ui.badge>

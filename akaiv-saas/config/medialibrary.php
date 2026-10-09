<?php

use Spatie\ImageOptimizer\Optimizers\Gifsicle;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Optipng;
use Spatie\ImageOptimizer\Optimizers\Pngquant;
use Spatie\ImageOptimizer\Optimizers\Svgo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

return [
    'media-library' => [
        'disk_name' => env('MEDIA_DISK', 's3'),
        'max_file_size' => 1024 * 1024 * 256,
        'queue_name' => '',
        'queue_connection' => env('QUEUE_CONNECTION', 'redis'),
        'path_generator' => DefaultPathGenerator::class,
        'file_namer' => DefaultFileNamer::class,
        'media_model' => Media::class,
        'remote' => [
            'extra_headers' => [
                'CacheControl' => 'max-age=604800',
            ],
        ],
        'responsive_images' => [
            'width_calculator' => FileSizeOptimizedWidthCalculator::class,
            'use_original_images' => true,
            'force_generate' => false,
        ],
        'use_temporary_directory_for_uploads' => true,
        'generate_responsive_images' => false,
        'image_optimizers' => [
            Jpegoptim::class => [
                '-m85',
                '--strip-all',
                '--all-progressive',
            ],
            Pngquant::class => [
                '--force',
                '--skip-if-larger',
                '--quality=85',
            ],
            Optipng::class => [
                '-i0',
                '-o2',
                '-quiet',
            ],
            Svgo::class => [
                '--disable=cleanupIDs',
            ],
            Gifsicle::class => [
                '-b',
                '-O3',
            ],
        ],
        'fallback_url' => '',
        'fallback_path' => '',
    ],
];

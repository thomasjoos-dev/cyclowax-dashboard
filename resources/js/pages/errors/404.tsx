import { Head, Link } from '@inertiajs/react';

export default function NotFound() {
    return (
        <>
            <Head title="Page Not Found" />
            <div className="flex min-h-screen items-center justify-center bg-background">
                <div className="mx-auto max-w-md space-y-4 p-8 text-center">
                    <h1 className="text-6xl font-bold text-muted-foreground">404</h1>
                    <h2 className="text-xl font-semibold text-foreground">Page not found</h2>
                    <p className="text-muted-foreground">The page you're looking for doesn't exist or has been moved.</p>
                    <Link
                        href="/"
                        className="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Back to dashboard
                    </Link>
                </div>
            </div>
        </>
    );
}

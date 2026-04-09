import { Head } from '@inertiajs/react';

export default function ServerError() {
    return (
        <>
            <Head title="Server Error" />
            <div className="flex min-h-screen items-center justify-center bg-background">
                <div className="mx-auto max-w-md space-y-4 p-8 text-center">
                    <h1 className="text-6xl font-bold text-muted-foreground">500</h1>
                    <h2 className="text-xl font-semibold text-foreground">Server error</h2>
                    <p className="text-muted-foreground">Something went wrong on our end. Please try again later.</p>
                    <button
                        onClick={() => window.location.reload()}
                        className="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                    >
                        Reload page
                    </button>
                </div>
            </div>
        </>
    );
}

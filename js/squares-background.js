class SquaresBackground {
    constructor(options = {}) {
        this.direction = options.direction || 'right';
        this.speed = options.speed || 1;
        this.borderColor = options.borderColor || '#333';
        this.squareSize = options.squareSize || 40;
        this.hoverFillColor = options.hoverFillColor || '#222';
        
        this.canvas = document.createElement('canvas');
        this.ctx = this.canvas.getContext('2d');
        this.requestRef = null;
        this.numSquaresX = 0;
        this.numSquaresY = 0;
        this.gridOffset = { x: 0, y: 0 };
        this.hoveredSquare = null;

        // Set canvas styles
        this.canvas.style.position = 'absolute';
        this.canvas.style.top = '0';
        this.canvas.style.left = '0';
        this.canvas.style.width = '100%';
        this.canvas.style.height = '100%';
        this.canvas.style.background = '#060606';
        this.canvas.style.zIndex = '1';
        
        // Enable pointer events for hover
        this.canvas.style.pointerEvents = 'auto';
    }

    init() {
        if (!this.canvas || !this.ctx) return;

        this.resizeCanvas();
        this.addEventListeners();
        this.startAnimation();
    }

    resizeCanvas() {
        const rect = this.canvas.getBoundingClientRect();
        this.canvas.width = rect.width;
        this.canvas.height = rect.height;
        this.numSquaresX = Math.ceil(this.canvas.width / this.squareSize) + 1;
        this.numSquaresY = Math.ceil(this.canvas.height / this.squareSize) + 1;
    }

    drawGrid() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        const startX = Math.floor(this.gridOffset.x / this.squareSize) * this.squareSize;
        const startY = Math.floor(this.gridOffset.y / this.squareSize) * this.squareSize;

        this.ctx.lineWidth = 0.5;

        // Calculate visible area
        const visibleSquaresX = Math.ceil(this.canvas.width / this.squareSize) + 1;
        const visibleSquaresY = Math.ceil(this.canvas.height / this.squareSize) + 1;

        for (let x = 0; x < visibleSquaresX; x++) {
            for (let y = 0; y < visibleSquaresY; y++) {
                const squareX = ((startX + (x * this.squareSize)) - (this.gridOffset.x % this.squareSize));
                const squareY = ((startY + (y * this.squareSize)) - (this.gridOffset.y % this.squareSize));

                if (
                    this.hoveredSquare &&
                    x === this.hoveredSquare.x &&
                    y === this.hoveredSquare.y
                ) {
                    this.ctx.fillStyle = this.hoverFillColor;
                    this.ctx.fillRect(squareX, squareY, this.squareSize, this.squareSize);
                }

                this.ctx.strokeStyle = this.borderColor;
                this.ctx.strokeRect(squareX, squareY, this.squareSize, this.squareSize);
            }
        }

        const gradient = this.ctx.createRadialGradient(
            this.canvas.width / 2,
            this.canvas.height / 2,
            0,
            this.canvas.width / 2,
            this.canvas.height / 2,
            Math.sqrt(Math.pow(this.canvas.width, 2) + Math.pow(this.canvas.height, 2)) / 2
        );
        gradient.addColorStop(0, 'rgba(6, 6, 6, 0)');
        gradient.addColorStop(1, '#060606');

        this.ctx.fillStyle = gradient;
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
    }

    handleMouseMove(event) {
        const rect = this.canvas.getBoundingClientRect();
        const scaleX = this.canvas.width / rect.width;
        const scaleY = this.canvas.height / rect.height;

        const mouseX = (event.clientX - rect.left) * scaleX;
        const mouseY = (event.clientY - rect.top) * scaleY;

        // Calculate grid position
        const gridX = Math.floor((mouseX + (this.gridOffset.x % this.squareSize)) / this.squareSize);
        const gridY = Math.floor((mouseY + (this.gridOffset.y % this.squareSize)) / this.squareSize);

        if (
            gridX >= 0 &&
            gridX < this.numSquaresX &&
            gridY >= 0 &&
            gridY < this.numSquaresY
        ) {
            this.hoveredSquare = { x: gridX, y: gridY };
        } else {
            this.hoveredSquare = null;
        }
    }

    handleMouseLeave() {
        this.hoveredSquare = null;
    }

    addEventListeners() {
        const boundResize = () => this.resizeCanvas();
        const boundMouseMove = (e) => this.handleMouseMove(e);
        const boundMouseLeave = () => this.handleMouseLeave();

        window.addEventListener('resize', boundResize);
        this.canvas.addEventListener('mousemove', boundMouseMove);
        this.canvas.addEventListener('mouseleave', boundMouseLeave);

        // Store bound functions for cleanup
        this._boundResize = boundResize;
        this._boundMouseMove = boundMouseMove;
        this._boundMouseLeave = boundMouseLeave;
    }

    startAnimation() {
        this.requestRef = requestAnimationFrame(() => this.updateAnimation());
    }

    updateAnimation() {
        const effectiveSpeed = Math.max(this.speed, 0.1);

        switch (this.direction) {
            case 'right':
                this.gridOffset.x = (this.gridOffset.x - effectiveSpeed + this.squareSize) % this.squareSize;
                break;
            case 'left':
                this.gridOffset.x = (this.gridOffset.x + effectiveSpeed + this.squareSize) % this.squareSize;
                break;
            case 'up':
                this.gridOffset.y = (this.gridOffset.y + effectiveSpeed + this.squareSize) % this.squareSize;
                break;
            case 'down':
                this.gridOffset.y = (this.gridOffset.y - effectiveSpeed + this.squareSize) % this.squareSize;
                break;
            case 'diagonal':
                this.gridOffset.x = (this.gridOffset.x - effectiveSpeed + this.squareSize) % this.squareSize;
                this.gridOffset.y = (this.gridOffset.y - effectiveSpeed + this.squareSize) % this.squareSize;
                break;
        }

        this.drawGrid();
        this.requestRef = requestAnimationFrame(() => this.updateAnimation());
    }

    mount(container) {
        container.appendChild(this.canvas);
        this.init();
    }

    destroy() {
        if (this.requestRef) {
            cancelAnimationFrame(this.requestRef);
        }
        
        // Remove event listeners using stored bound functions
        window.removeEventListener('resize', this._boundResize);
        this.canvas.removeEventListener('mousemove', this._boundMouseMove);
        this.canvas.removeEventListener('mouseleave', this._boundMouseLeave);
        
        this.canvas.remove();
    }
} 